<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Response;

class PhitomasBNMController extends Controller
{
    public function exchangeRates(Request $request){ 

        $client = new Client(); //site url, username, password
        $token = $client->request('GET', $request->input('csi_url').'/ido/token/'.$request->input('csi_site').'/'.$request->input('csi_username').'/'.$request->input('csi_password'));
        $tokenData = json_decode($token->getBody(), true)['Token'];
        
        if($tokenData == null || $tokenData == ""){
            $tokenErrorMessage = json_decode($token->getBody(), true)['Message'];
            return Response::json(array(
                'Success'   => false,
                'Code'      =>  404,
                'Message'   =>  $tokenErrorMessage
            ), 404);
        }

        $bnmMapClient = new Client(['headers' => ['Accept' => 'application/vnd.BNM.API.v1+json'], 'http_errors' => false]);
        $quote = 'rm';
        $res = $bnmMapClient->request('GET', 'https://api.bnm.gov.my/public/exchange-rate');
        $bnmData = $res->getBody()->getContents();
        $bnmDatas = json_decode($bnmData, true)['data'];

        $loadCollectionClient = new Client();
        $loadCollectionIDO = 'UserDefinedTypeValues';
        $loadCollectionProperties = 'Value, TypeName';
        $loadCollectionFilter = "TypeName = 'ILNT_BNMCurrCode'";
        $loadBNMUDTRes = $client->request('GET', $request->input('csi_url') . "/ido/load/" . $loadCollectionIDO . "?properties=" . $loadCollectionProperties . "&filter=" . $loadCollectionFilter, ['headers' => ['Authorization' => $tokenData]]);
        $loadBNMUDTResponse = json_decode($loadBNMUDTRes->getBody()->getContents(), true)['Items'];

        if($request->input('is_get_bnm_currency') == 1){
            foreach ($bnmDatas as $bnmMapCurrData) {
                $isCurrCodeExist = 0;
                foreach ($loadBNMUDTResponse as $sytelineMapCurrData) {
                    if ($sytelineMapCurrData['Value'] == $bnmMapCurrData['currency_code'] ){
                        $isCurrCodeExist = 1;
                    }
                }
                if($isCurrCodeExist == 0){
                    $currResult[] = [
                        [
                            'Name' => "TypeName",
                            'Value' => 'ILNT_BNMCurrCode',
                            'Modified' => true,
                            'ISNull' => false,
                        ],
                        [
                            'Name' => "Value",
                            'Value' => $bnmMapCurrData['currency_code'],
                            'Modified' => true,
                            'ISNull' => false,
                        ]
                    ];
                }
            }
            if(count($currResult) > 0){
                foreach ($currResult as $data) {
                    $currChanges[] = [
                        'Action' => 1,
                        'ItemId' => "",
                        'UpdateLocking' => "1",
                        'Properties' => $data
                    ];
                }
                
                $insertCurrBody['Changes'] = $currChanges;
                $insertRes = $client->request('POST', $request->input('csi_url').'/ido/update/UserDefinedTypeValues?refresh=true', ['headers' => ['Authorization' => $tokenData], 'json' => $insertCurrBody]);
                $insertResponse = json_decode($insertRes->getBody()->getContents(), true);
            }
        }
        
        $loadCollectionIDO = 'ILNT_CurrencyMap_mst';
        $loadCollectionProperties = 'map_curr_code, curr_code';
        $loadCollectionFilter = '';
        if ($request->input('to_currency') != "") {
            $loadCollectionFilter = $loadCollectionFilter. "curr_code >= '" .$request->input('from_currency')."'";
        }
        if ($request->input('from_currency') != "") {
            if ($loadCollectionFilter != ""){
                $loadCollectionFilter = $loadCollectionFilter." AND ";
            }
            $loadCollectionFilter = $loadCollectionFilter. "curr_code <= '" .$request->input('to_currency')."'";
        } 
        $validateCheckLotExistsRes = $client->request('GET', $request->input('csi_url') . "/ido/load/" . $loadCollectionIDO . "?properties=" . $loadCollectionProperties . "&filter=" . $loadCollectionFilter, ['headers' => ['Authorization' => $tokenData]]);
        $to_currency = json_decode($validateCheckLotExistsRes->getBody(), true);
        
        if(!$to_currency['Success']){
            $errorMessage = $to_currency['Message'];
            return Response::json(array(
                'Success'   => false,
                'code'      =>  404,
                'Message'   =>  $errorMessage
            ), 404);
        }
        
        if(count($to_currency['Items']) == 0){
            return Response::json(array(
                'Success'   => false,
                'code'      => 404,
                'Message'   => "Currency Code doesnt match"
            ), 404);
        }

        foreach ($to_currency['Items'] as $data) {
            $bnmClient = new Client(['headers' => ['Accept' => 'application/vnd.BNM.API.v1+json'], 'http_errors' => false]);
            $quote = 'rm';
            $res = $bnmClient->request('GET', $request->input('bnm_url'). $data['map_curr_code'] . '/date/' . $request->rate_date . '?session='.$request->rate_session.'&quote=' . $quote);
            $bnmData = $res->getBody()->getContents();
            $datas = json_decode($bnmData, true);

            if($res->getStatusCode()!= 404){
                if ($request->input('type') == 'M') {
                    $SellRate = $datas['data']['rate']['middle_rate'];
                    $BuyRate = $datas['data']['rate']['middle_rate'];
                } else if ($request->input('type') == 'S') {
                    $SellRate = $datas['data']['rate']['selling_rate'];
                    $BuyRate = $datas['data']['rate']['selling_rate'];
                } else if ($request->input('type') == 'B') {
                    $SellRate = $datas['data']['rate']['buying_rate'];
                    $BuyRate = $datas['data']['rate']['buying_rate'];
                } else { //x
                    $SellRate = $datas['data']['rate']['selling_rate'];
                    $BuyRate = $datas['data']['rate']['buying_rate'];
                }

                $result[] = [
                    [
                        'Name' => "FromCurrCode",
                        'Value' => 'MYR',
                        'Modified' => true,
                        'ISNull' => false,
                    ],
                    [
                        'Name' => "ToCurrCode",
                        'Value' => $data['curr_code'],
                        'Modified' => true,
                        'ISNull' => false,
                    ],
                    [
                        'Name' => "SellRate",
                        'Value' => (string) $SellRate,
                        'Modified' => true,
                        'ISNull' => false,
                    ],
                    [
                        'Name' => "BuyRate",
                        'Value' => (string) $BuyRate,
                        'Modified' => true,
                        'ISNull' => false,
                    ],
                    [
                        'Name' => "EffDate",
                        'Value' => $request->post_date,
                        'Modified' => true,
                        'ISNull' => false,
                    ]
                ];
            } else {
                return [
                    'Currency Code' => $data['curr_code'],
                    'Date' => $request->rate_date,
                    'Message' => $datas['message'],
                    'Success'=> false
                ];
            }
        }

        foreach ($result as $data) {
            $changes[] = [
                'Action' => 1,
                'ItemId' => "",
                'UpdateLocking' => "1",
                'Properties' => $data
            ];
        }
         
        $insertBody['Changes'] = $changes;
        $insertRes = $client->request('POST', $request->input('csi_url').'/ido/update/SLCurrates?refresh=true', ['headers' => ['Authorization' => $tokenData], 'json' => $insertBody]);
        $insertResponse = json_decode($insertRes->getBody()->getContents(), true);

        return response()->json($insertResponse);
    }
}
