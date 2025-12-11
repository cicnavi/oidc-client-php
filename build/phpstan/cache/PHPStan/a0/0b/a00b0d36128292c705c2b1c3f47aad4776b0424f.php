<?php declare(strict_types = 1);

// odsl-/app/src
return \PHPStan\Cache\CacheItem::__set_state(array(
   'variableKey' => 'v1',
   'data' => 
  array (
    '/app/src/DataStore/PhpSessionDataStore.php' => 
    array (
      0 => '7686fe54d3a0fc10e5e967f85383c024a5216677',
      1 => 
      array (
        0 => 'cicnavi\\oidc\\datastore\\phpsessiondatastore',
      ),
      2 => 
      array (
        0 => 'cicnavi\\oidc\\datastore\\__construct',
        1 => 'cicnavi\\oidc\\datastore\\startsession',
        2 => 'cicnavi\\oidc\\datastore\\validatephpsession',
        3 => 'cicnavi\\oidc\\datastore\\get',
        4 => 'cicnavi\\oidc\\datastore\\exists',
        5 => 'cicnavi\\oidc\\datastore\\put',
        6 => 'cicnavi\\oidc\\datastore\\delete',
      ),
      3 => 
      array (
      ),
    ),
    '/app/src/DataStore/Interfaces/DataStoreInterface.php' => 
    array (
      0 => 'ba67557214cbd760c2452828e2b451cfe5bebfb1',
      1 => 
      array (
        0 => 'cicnavi\\oidc\\datastore\\interfaces\\datastoreinterface',
      ),
      2 => 
      array (
        0 => 'cicnavi\\oidc\\datastore\\interfaces\\exists',
        1 => 'cicnavi\\oidc\\datastore\\interfaces\\get',
        2 => 'cicnavi\\oidc\\datastore\\interfaces\\put',
        3 => 'cicnavi\\oidc\\datastore\\interfaces\\delete',
      ),
      3 => 
      array (
      ),
    ),
    '/app/src/DataStore/DataHandlers/StateNonce.php' => 
    array (
      0 => '0d13a38c40575ab0f2211b3f3fe0c30fdc57446a',
      1 => 
      array (
        0 => 'cicnavi\\oidc\\datastore\\datahandlers\\statenonce',
      ),
      2 => 
      array (
        0 => 'cicnavi\\oidc\\datastore\\datahandlers\\get',
        1 => 'cicnavi\\oidc\\datastore\\datahandlers\\verify',
        2 => 'cicnavi\\oidc\\datastore\\datahandlers\\remove',
        3 => 'cicnavi\\oidc\\datastore\\datahandlers\\validateparameterkey',
      ),
      3 => 
      array (
      ),
    ),
    '/app/src/DataStore/DataHandlers/Pkce.php' => 
    array (
      0 => 'bd259ce8a78147a4a81b58ce3726eb9b58fea909',
      1 => 
      array (
        0 => 'cicnavi\\oidc\\datastore\\datahandlers\\pkce',
      ),
      2 => 
      array (
        0 => 'cicnavi\\oidc\\datastore\\datahandlers\\getcodeverifier',
        1 => 'cicnavi\\oidc\\datastore\\datahandlers\\generatecodechallengefromcodeverifier',
        2 => 'cicnavi\\oidc\\datastore\\datahandlers\\removecodeverifier',
        3 => 'cicnavi\\oidc\\datastore\\datahandlers\\validatepkcecodechallengemethod',
      ),
      3 => 
      array (
      ),
    ),
    '/app/src/DataStore/DataHandlers/Interfaces/DataHandlerInterface.php' => 
    array (
      0 => '1ed8dc9c40e7b487c8053f2eaa35baf223efcece',
      1 => 
      array (
        0 => 'cicnavi\\oidc\\datastore\\datahandlers\\interfaces\\datahandlerinterface',
      ),
      2 => 
      array (
        0 => 'cicnavi\\oidc\\datastore\\datahandlers\\interfaces\\setstore',
      ),
      3 => 
      array (
      ),
    ),
    '/app/src/DataStore/DataHandlers/Interfaces/PkceDataHandlerInterface.php' => 
    array (
      0 => '933582a5b3dcfaf05098bfa4f7b66ff98fdd3ee3',
      1 => 
      array (
        0 => 'cicnavi\\oidc\\datastore\\datahandlers\\interfaces\\pkcedatahandlerinterface',
      ),
      2 => 
      array (
        0 => 'cicnavi\\oidc\\datastore\\datahandlers\\interfaces\\getcodeverifier',
        1 => 'cicnavi\\oidc\\datastore\\datahandlers\\interfaces\\generatecodechallengefromcodeverifier',
        2 => 'cicnavi\\oidc\\datastore\\datahandlers\\interfaces\\removecodeverifier',
        3 => 'cicnavi\\oidc\\datastore\\datahandlers\\interfaces\\validatepkcecodechallengemethod',
      ),
      3 => 
      array (
      ),
    ),
    '/app/src/DataStore/DataHandlers/Interfaces/StateNonceDataHandlerInterface.php' => 
    array (
      0 => '64e4bc16eaccf5a69cfed533bb2d37a0f33ddb08',
      1 => 
      array (
        0 => 'cicnavi\\oidc\\datastore\\datahandlers\\interfaces\\statenoncedatahandlerinterface',
      ),
      2 => 
      array (
        0 => 'cicnavi\\oidc\\datastore\\datahandlers\\interfaces\\get',
        1 => 'cicnavi\\oidc\\datastore\\datahandlers\\interfaces\\verify',
        2 => 'cicnavi\\oidc\\datastore\\datahandlers\\interfaces\\remove',
      ),
      3 => 
      array (
      ),
    ),
    '/app/src/DataStore/DataHandlers/AbstractDataHandler.php' => 
    array (
      0 => '5d6e9ba29ff1b46f9c9d5424125ead95df4d0a80',
      1 => 
      array (
        0 => 'cicnavi\\oidc\\datastore\\datahandlers\\abstractdatahandler',
      ),
      2 => 
      array (
        0 => 'cicnavi\\oidc\\datastore\\datahandlers\\__construct',
        1 => 'cicnavi\\oidc\\datastore\\datahandlers\\setstore',
      ),
      3 => 
      array (
      ),
    ),
    '/app/src/Helpers/HttpHelper.php' => 
    array (
      0 => '38596e33fc0e5fb1c8c44877d50b6b745aaea131',
      1 => 
      array (
        0 => 'cicnavi\\oidc\\helpers\\httphelper',
      ),
      2 => 
      array (
        0 => 'cicnavi\\oidc\\helpers\\isrequesthttpsecure',
        1 => 'cicnavi\\oidc\\helpers\\normalizesessioncookieparams',
      ),
      3 => 
      array (
      ),
    ),
    '/app/src/Helpers/StringHelper.php' => 
    array (
      0 => '1aee29879ed801ec8707e01b3caca9f5b19499cd',
      1 => 
      array (
        0 => 'cicnavi\\oidc\\helpers\\stringhelper',
      ),
      2 => 
      array (
        0 => 'cicnavi\\oidc\\helpers\\random',
      ),
      3 => 
      array (
      ),
    ),
    '/app/src/Http/RequestFactory.php' => 
    array (
      0 => 'e171a480c03843f2c6542835136176538cfb7d77',
      1 => 
      array (
        0 => 'cicnavi\\oidc\\http\\requestfactory',
      ),
      2 => 
      array (
        0 => 'cicnavi\\oidc\\http\\createrequest',
      ),
      3 => 
      array (
      ),
    ),
    '/app/src/PreRegisteredClient.php' => 
    array (
      0 => '253e430b0ea0a6b85be2cb1092ce39a1778a8be3',
      1 => 
      array (
        0 => 'cicnavi\\oidc\\preregisteredclient',
      ),
      2 => 
      array (
        0 => 'cicnavi\\oidc\\__construct',
        1 => 'cicnavi\\oidc\\validatecache',
        2 => 'cicnavi\\oidc\\authorize',
        3 => 'cicnavi\\oidc\\getuserdata',
        4 => 'cicnavi\\oidc\\validateauthorizationresponse',
        5 => 'cicnavi\\oidc\\requesttokendata',
        6 => 'cicnavi\\oidc\\getclaims',
        7 => 'cicnavi\\oidc\\getdatafromidtoken',
        8 => 'cicnavi\\oidc\\validatejwksuricontentarary',
        9 => 'cicnavi\\oidc\\getjwksuricontent',
        10 => 'cicnavi\\oidc\\requestjwksuricontent',
        11 => 'cicnavi\\oidc\\requestuserdatafromuserinfoendpoint',
        12 => 'cicnavi\\oidc\\getmetadata',
        13 => 'cicnavi\\oidc\\validatetokendataarray',
        14 => 'cicnavi\\oidc\\validatehttpresponseok',
        15 => 'cicnavi\\oidc\\getdecodedhttpresponsejson',
        16 => 'cicnavi\\oidc\\decodejsonorthrow',
        17 => 'cicnavi\\oidc\\validateuserinfoclaims',
        18 => 'cicnavi\\oidc\\validateidtokenanduserinfoclaims',
        19 => 'cicnavi\\oidc\\reinitializecache',
      ),
      3 => 
      array (
      ),
    ),
    '/app/src/Cache/FileCache.php' => 
    array (
      0 => '4c3ce132556df331c93a741ebe043ec0d39596bc',
      1 => 
      array (
        0 => 'cicnavi\\oidc\\cache\\filecache',
      ),
      2 => 
      array (
        0 => 'cicnavi\\oidc\\cache\\__construct',
      ),
      3 => 
      array (
      ),
    ),
    '/app/src/Exceptions/OidcClientException.php' => 
    array (
      0 => '924aa64f72d0d6ed60789edd931a4125e8bae076',
      1 => 
      array (
        0 => 'cicnavi\\oidc\\exceptions\\oidcclientexception',
      ),
      2 => 
      array (
      ),
      3 => 
      array (
      ),
    ),
    '/app/src/Exceptions/Interfaces/OidcClientExceptionInterface.php' => 
    array (
      0 => '81336341ae42c43fb9fdbc0c989177f93aac8bbc',
      1 => 
      array (
        0 => 'cicnavi\\oidc\\exceptions\\interfaces\\oidcclientexceptioninterface',
      ),
      2 => 
      array (
      ),
      3 => 
      array (
      ),
    ),
    '/app/src/Interfaces/MetadataInterface.php' => 
    array (
      0 => 'cf1a4092adb7d050309b76fb456c39a067537042',
      1 => 
      array (
        0 => 'cicnavi\\oidc\\interfaces\\metadatainterface',
      ),
      2 => 
      array (
        0 => 'cicnavi\\oidc\\interfaces\\get',
      ),
      3 => 
      array (
      ),
    ),
    '/app/src/OpMetadata.php' => 
    array (
      0 => 'bea823405f2675560061f6ffdd4003eedac4058d',
      1 => 
      array (
        0 => 'cicnavi\\oidc\\opmetadata',
      ),
      2 => 
      array (
        0 => 'cicnavi\\oidc\\__construct',
        1 => 'cicnavi\\oidc\\get',
        2 => 'cicnavi\\oidc\\requestmetadata',
        3 => 'cicnavi\\oidc\\isvalidmetadata',
        4 => 'cicnavi\\oidc\\validatemetadata',
      ),
      3 => 
      array (
      ),
    ),
  ),
));