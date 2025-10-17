<?php

function searchKeywordV2($query, $count = 50, $page = 1) {
    // 네이버 플레이스 크롤링
    $page = (int)$page;
    if(!$page) $page = 1;
    $count = (int)$count;
    $count = $count < 100 ? $count : 100;
    if(!$count) $count = 50;
    $start = ($page-1)*$count + 1;

    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://pcmap-api.place.naver.com/graphql',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS =>'[{"operationName":"getPlacesList","variables":{"useReverseGeocode":true,"input":{"query":"'.$query.'","start":'.$start.',"display":'.$count.',"adult":false,"spq":false,"queryRank":"","deviceType":"pcmap"},"isNmap":false,"isBounds":true,"reverseGeocodingInput":{}},"query":"query getPlacesList($input: PlacesInput, $isNmap: Boolean!, $isBounds: Boolean!, $reverseGeocodingInput: ReverseGeocodingInput, $useReverseGeocode: Boolean = false) {\\n  businesses: places(input: $input) {\\n    total\\n    items {\\n      id\\n      name\\n      category\\n      detailCid {\\n        c0\\n        c1\\n        c2\\n        c3\\n        __typename\\n      }\\n      categoryCodeList\\n      roadAddress\\n      address\\n      fullAddress\\n      phone\\n      talktalkUrl\\n      virtualPhone\\n      markerId @include(if: $isNmap)\\n      markerLabel @include(if: $isNmap) {\\n        text\\n        style\\n        stylePreset\\n        __typename\\n      }\\n      imageMarker @include(if: $isNmap) {\\n        marker\\n        markerSelected\\n        __typename\\n      }\\n      oilPrice @include(if: $isNmap) {\\n        gasoline\\n        diesel\\n        lpg\\n        __typename\\n      }\\n      }\\n    optionsForMap @include(if: $isBounds) {\\n      ...OptionsForMap\\n      displayCorrectAnswer\\n      correctAnswerPlaceId\\n      __typename\\n    }\\n    __typename\\n  }\\n  reverseGeocodingAddr(input: $reverseGeocodingInput) @include(if: $useReverseGeocode) {\\n    ...ReverseGeocodingAddr\\n    __typename\\n  }\\n}\\n\\nfragment OptionsForMap on OptionsForMap {\\n  maxZoom\\n  minZoom\\n  includeMyLocation\\n  maxIncludePoiCount\\n  center\\n  spotId\\n  keepMapBounds\\n  __typename\\n}\\n\\nfragment ReverseGeocodingAddr on ReverseGeocodingResult {\\n  rcode\\n  region\\n  __typename\\n}"}]',
        CURLOPT_HTTPHEADER => array(
            'accept: */*',
            'accept-language: ko',
            'cache-control: no-cache',
            'content-type: application/json',
            'origin: https://pcmap.place.naver.com',
            'pragma: no-cache',
            'priority: u=1, i',
            'referer: https://pcmap.place.naver.com/place/list?level=top&entry=pll&query='.urlencode($query),
            'sec-ch-ua: "Whale";v="3", "Not-A.Brand";v="8", "Chromium";v="124"',
            'sec-ch-ua-mobile: ?0',
            'sec-ch-ua-platform: "Windows"',
            'sec-fetch-dest: empty',
            'sec-fetch-mode: cors',
            'sec-fetch-site: same-site',
            'user-agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Whale/3.26.244.14 Safari/537.36',
            'x-wtm-graphql: eyJhcmciOiLsl5DsiqTthYzti7EiLCJ0eXBlIjoicGxhY2UiLCJzb3VyY2UiOiJwbGFjZSJ9'
        ),
    ));

    $response = curl_exec($curl);
    curl_close($curl);
    //var_dump($response);
    $search_contents = json_decode($response, true);
    $return = array();
    $return['keyword'] = $query;
    $return['totalCount'] = $search_contents[0]['data']['businesses']['total'];
    //var_dump($search_contents);
    $return['lists'] = array();
    foreach($search_contents[0]['data']['businesses']['items'] as $k => $item) {
        $item['rank'] = $start + $k;
        $item['tel'] = $item['phone'];
        if(!$item['tel'] && $item['virtualPhone']) $item['tel'] = $item['virtualPhone'];
        if(!$item['tel']) $item['tel'] = '';
        unset($item['phone']);
        $return['lists'][] = $item;
    }
    return $return;
}

function api_msg($msg, $code='1000', $data = false) {
    // 메시지 json
    $result = array();
    $result['code'] = $code;
    $result['msg'] = $msg;
    if($code != '0000') {
        die(json_encode($result));
    }
    if($data) {
        $result['result'] = $data;
    }
    echo json_encode($result);
}