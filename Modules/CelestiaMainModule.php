<?php declare(strict_types=1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the main Celestia module. */

final class CelestiaMainModule extends CosmosMainModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'celestia';
        $this->module = 'celestia-main';
        $this->is_main = true;
        $this->first_block_id = 1;
        $this->first_block_date = '2023-10-31';
        $this->currency = 'celestia';
        $this->currency_details = ['name' => 'Celestia', 'symbol' => 'TIA', 'decimals' => 8, 'description' => null];

        // Cosmos-specific
        $this->cosmos_special_addresses = [
            // At each block, all fees received are transferred to fee_collector.
            'fee_collector' => 'celestia17xpfvakm2amg962yls6f84z3kell8c5lpnjs3s'
        ];
        $this->cosmos_known_denoms = ['utia' => 0];
        $this->cosmos_coin_events_fork = 0;

        $this->tests = [
            ['block' => 689399, 'transaction' => '275137ad9b3ee553fc28baa6c91102be8b137f59d84258cb82ca505d3689c2ad', 'result' => 'a:1:{s:6:"events";a:4:{i:0;a:8:{s:11:"transaction";s:64:"275137ad9b3ee553fc28baa6c91102be8b137f59d84258cb82ca505d3689c2ad";s:8:"sort_key";i:0;s:7:"address";s:47:"celestia1tv85ggjuxnxden0v44assh854k88t9pca3ardk";s:6:"effect";s:5:"-2536";s:6:"failed";s:1:"f";s:5:"extra";s:1:"f";s:5:"block";i:689399;s:4:"time";s:19:"2024-02-01 22:45:13";}i:1;a:8:{s:11:"transaction";s:64:"275137ad9b3ee553fc28baa6c91102be8b137f59d84258cb82ca505d3689c2ad";s:8:"sort_key";i:1;s:7:"address";s:47:"celestia17xpfvakm2amg962yls6f84z3kell8c5lpnjs3s";s:6:"effect";s:4:"2536";s:6:"failed";s:1:"f";s:5:"extra";s:1:"f";s:5:"block";i:689399;s:4:"time";s:19:"2024-02-01 22:45:13";}i:2;a:8:{s:11:"transaction";s:64:"275137ad9b3ee553fc28baa6c91102be8b137f59d84258cb82ca505d3689c2ad";s:8:"sort_key";i:2;s:7:"address";s:47:"celestia1tv85ggjuxnxden0v44assh854k88t9pca3ardk";s:6:"effect";s:9:"-11600000";s:6:"failed";s:1:"f";s:5:"extra";N;s:5:"block";i:689399;s:4:"time";s:19:"2024-02-01 22:45:13";}i:3;a:8:{s:11:"transaction";s:64:"275137ad9b3ee553fc28baa6c91102be8b137f59d84258cb82ca505d3689c2ad";s:8:"sort_key";i:3;s:7:"address";s:47:"celestia17sael2kcmm8npe2pmkxj3un90xfg60vv9l4aes";s:6:"effect";s:8:"11600000";s:6:"failed";s:1:"f";s:5:"extra";N;s:5:"block";i:689399;s:4:"time";s:19:"2024-02-01 22:45:13";}}}'],
            ['block' => 9956, 'result' => 'a:2:{s:6:"events";a:12:{i:0;a:8:{s:11:"transaction";s:64:"5994338cb722b6a012dfea98dbc10d09aadb66d9c40872f937309d7b65476a9f";s:8:"sort_key";i:0;s:7:"address";s:47:"celestia16m48j88mlw2smhc8nyurznt4jl9nqgyqegz3da";s:6:"effect";s:7:"-136491";s:6:"failed";s:1:"f";s:5:"extra";s:1:"f";s:5:"block";i:9956;s:4:"time";s:19:"2023-11-01 22:53:18";}i:1;a:8:{s:11:"transaction";s:64:"5994338cb722b6a012dfea98dbc10d09aadb66d9c40872f937309d7b65476a9f";s:8:"sort_key";i:1;s:7:"address";s:47:"celestia17xpfvakm2amg962yls6f84z3kell8c5lpnjs3s";s:6:"effect";s:6:"136491";s:6:"failed";s:1:"f";s:5:"extra";s:1:"f";s:5:"block";i:9956;s:4:"time";s:19:"2023-11-01 22:53:18";}i:2;a:8:{s:11:"transaction";s:64:"90b27c6570cbb7a3c59cb43a43efe7ddf4fa82edf50f235b67fc39ffe9eba34f";s:8:"sort_key";i:2;s:7:"address";s:47:"celestia1zpaqkahypxx680p0hnsnw5707zpg5q8mcgvygs";s:6:"effect";s:6:"-37818";s:6:"failed";s:1:"f";s:5:"extra";s:1:"f";s:5:"block";i:9956;s:4:"time";s:19:"2023-11-01 22:53:18";}i:3;a:8:{s:11:"transaction";s:64:"90b27c6570cbb7a3c59cb43a43efe7ddf4fa82edf50f235b67fc39ffe9eba34f";s:8:"sort_key";i:3;s:7:"address";s:47:"celestia17xpfvakm2amg962yls6f84z3kell8c5lpnjs3s";s:6:"effect";s:5:"37818";s:6:"failed";s:1:"f";s:5:"extra";s:1:"f";s:5:"block";i:9956;s:4:"time";s:19:"2023-11-01 22:53:18";}i:4;a:8:{s:11:"transaction";s:64:"90b27c6570cbb7a3c59cb43a43efe7ddf4fa82edf50f235b67fc39ffe9eba34f";s:8:"sort_key";i:4;s:7:"address";s:47:"celestia1zpaqkahypxx680p0hnsnw5707zpg5q8mcgvygs";s:6:"effect";s:9:"-81270886";s:6:"failed";s:1:"f";s:5:"extra";N;s:5:"block";i:9956;s:4:"time";s:19:"2023-11-01 22:53:18";}i:5;a:8:{s:11:"transaction";s:64:"90b27c6570cbb7a3c59cb43a43efe7ddf4fa82edf50f235b67fc39ffe9eba34f";s:8:"sort_key";i:5;s:7:"address";s:47:"celestia1fl48vsnmsdzcv85q5d2q4z5ajdha8yu3y3clr6";s:6:"effect";s:8:"81270886";s:6:"failed";s:1:"f";s:5:"extra";N;s:5:"block";i:9956;s:4:"time";s:19:"2023-11-01 22:53:18";}i:6;a:8:{s:11:"transaction";N;s:8:"sort_key";i:6;s:7:"address";s:8:"the-void";s:6:"effect";s:9:"-29937517";s:6:"failed";s:1:"f";s:5:"extra";N;s:5:"block";i:9956;s:4:"time";s:19:"2023-11-01 22:53:18";}i:7;a:8:{s:11:"transaction";N;s:8:"sort_key";i:7;s:7:"address";s:47:"celestia1m3h30wlvsf8llruxtpukdvsy0km2kum8emkgad";s:6:"effect";s:8:"29937517";s:6:"failed";s:1:"f";s:5:"extra";N;s:5:"block";i:9956;s:4:"time";s:19:"2023-11-01 22:53:18";}i:8;a:8:{s:11:"transaction";N;s:8:"sort_key";i:8;s:7:"address";s:47:"celestia1m3h30wlvsf8llruxtpukdvsy0km2kum8emkgad";s:6:"effect";s:9:"-29937517";s:6:"failed";s:1:"f";s:5:"extra";N;s:5:"block";i:9956;s:4:"time";s:19:"2023-11-01 22:53:18";}i:9;a:8:{s:11:"transaction";N;s:8:"sort_key";i:9;s:7:"address";s:47:"celestia17xpfvakm2amg962yls6f84z3kell8c5lpnjs3s";s:6:"effect";s:8:"29937517";s:6:"failed";s:1:"f";s:5:"extra";N;s:5:"block";i:9956;s:4:"time";s:19:"2023-11-01 22:53:18";}i:10;a:8:{s:11:"transaction";N;s:8:"sort_key";i:10;s:7:"address";s:47:"celestia17xpfvakm2amg962yls6f84z3kell8c5lpnjs3s";s:6:"effect";s:9:"-30087517";s:6:"failed";s:1:"f";s:5:"extra";N;s:5:"block";i:9956;s:4:"time";s:19:"2023-11-01 22:53:18";}i:11;a:8:{s:11:"transaction";N;s:8:"sort_key";i:11;s:7:"address";s:47:"celestia1jv65s3grqf6v6jl3dp4t6c9t9rk99cd8k44vnj";s:6:"effect";s:8:"30087517";s:6:"failed";s:1:"f";s:5:"extra";N;s:5:"block";i:9956;s:4:"time";s:19:"2023-11-01 22:53:18";}}s:10:"currencies";N;}'],
        ];
    }
}
