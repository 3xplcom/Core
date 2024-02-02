<?php declare(strict_types=1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the IBC Sei module. */

final class SeiIBCModule extends CosmosIBCModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'sei';
        $this->module = 'sei-ibc';
        $this->is_main = false;
        $this->first_block_date = '2022-05-28';

        // Cosmos-specific
        $this->cosmos_special_addresses = [];
        $this->cosmos_coin_events_fork = 0;
        $this->extra_features = [CosmosSpecialFeatures::HasNotCodeField];

        $this->tests = [
            // Transfer
            ['block' => 53274044, 'result' => 'a:2:{s:6:"events";a:4:{i:0;a:9:{s:11:"transaction";s:64:"c919178e60f0e6ad7224691b229e29ef6126c7e69dfb4a39ee8031ccc6bca335";s:8:"sort_key";i:0;s:7:"address";s:42:"sei16wmlr9tuyf4p2mr8ftxffdtp85vcpq8wq9e46d";s:8:"currency";s:68:"ibc_F082B65C88E4B6D5EF1DB243CDA1D331D002759E938A0F5CD3FFDC5D53B3E349";s:6:"effect";s:10:"-600318370";s:6:"failed";s:1:"f";s:5:"extra";N;s:5:"block";i:53274044;s:4:"time";s:19:"2024-01-22 11:43:06";}i:1;a:9:{s:11:"transaction";s:64:"c919178e60f0e6ad7224691b229e29ef6126c7e69dfb4a39ee8031ccc6bca335";s:8:"sort_key";i:1;s:7:"address";s:62:"sei1d2r4s2q8kumpmvx6dyj77klhgm5e6fs9njmmz6ye7ukqa77ddtdsu72dc3";s:8:"currency";s:68:"ibc_F082B65C88E4B6D5EF1DB243CDA1D331D002759E938A0F5CD3FFDC5D53B3E349";s:6:"effect";s:9:"600318370";s:6:"failed";s:1:"f";s:5:"extra";N;s:5:"block";i:53274044;s:4:"time";s:19:"2024-01-22 11:43:06";}i:2;a:9:{s:11:"transaction";s:64:"c919178e60f0e6ad7224691b229e29ef6126c7e69dfb4a39ee8031ccc6bca335";s:8:"sort_key";i:2;s:7:"address";s:62:"sei1d2r4s2q8kumpmvx6dyj77klhgm5e6fs9njmmz6ye7ukqa77ddtdsu72dc3";s:8:"currency";s:68:"ibc_F082B65C88E4B6D5EF1DB243CDA1D331D002759E938A0F5CD3FFDC5D53B3E349";s:6:"effect";s:10:"-600318370";s:6:"failed";s:1:"f";s:5:"extra";N;s:5:"block";i:53274044;s:4:"time";s:19:"2024-01-22 11:43:06";}i:3;a:9:{s:11:"transaction";s:64:"c919178e60f0e6ad7224691b229e29ef6126c7e69dfb4a39ee8031ccc6bca335";s:8:"sort_key";i:3;s:7:"address";s:62:"sei1prqsm5y7tumchkugx5wcz4y0fya7cta5ch9h366em806frq4jtnqlrazgt";s:8:"currency";s:68:"ibc_F082B65C88E4B6D5EF1DB243CDA1D331D002759E938A0F5CD3FFDC5D53B3E349";s:6:"effect";s:9:"600318370";s:6:"failed";s:1:"f";s:5:"extra";N;s:5:"block";i:53274044;s:4:"time";s:19:"2024-01-22 11:43:06";}}s:10:"currencies";a:1:{i:0;a:4:{s:2:"id";s:68:"ibc_F082B65C88E4B6D5EF1DB243CDA1D331D002759E938A0F5CD3FFDC5D53B3E349";s:4:"name";s:5:"uusdc";s:11:"description";s:18:"transfer/channel-2";s:8:"decimals";i:6;}}}']
        ];
    }
}
