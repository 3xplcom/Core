<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module works with the TEP-74 standard, see
 *  https://github.com/ton-blockchain/TEPs/blob/master/text/0074-jettons-standard.md */  

final class TONJettonModule extends TONLikeTokensModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'ton';
        $this->module = 'ton-jetton';
        $this->is_main = false;
        $this->first_block_date = '2019-11-15';
        $this->first_block_id = 1;

        // TONLikeTokensModule
        $this->workchain = '0'; // BaseChain
        $this->currency_type = CurrencyType::FT;

        // Tests
        $this->tests = [
            ['block' => 40470016, 'result' => 'a:2:{s:6:"events";a:6:{i:0;a:8:{s:11:"transaction";s:64:"27c9ae28bc1cbf57cd78caad716f566beef274d361ea8ab0d4973e68010fbaa4";s:7:"address";s:48:"EQDCTl1v-TPqf-X26w3JVqYokkAYsMz6BQK5Hkd7NeYWYjF8";s:6:"effect";s:13:"-614900000000";s:13:"extra_indexed";s:29:"(0,a800000000000000,45734519)";s:8:"currency";s:48:"EQCV5dXNrWVU1z7VidEKvXL5iB_PXs2zm9LLe9bPgSklde0z";s:5:"block";i:40470016;s:4:"time";s:19:"2024-09-18 10:51:06";s:8:"sort_key";i:0;}i:1;a:8:{s:11:"transaction";s:64:"27c9ae28bc1cbf57cd78caad716f566beef274d361ea8ab0d4973e68010fbaa4";s:7:"address";s:48:"EQAJZF4OyGerASxjdBnsEhJTpLGCC8okIr4ZzkBTTYbZbMhE";s:6:"effect";s:12:"614900000000";s:13:"extra_indexed";s:29:"(0,a800000000000000,45734519)";s:8:"currency";s:48:"EQCV5dXNrWVU1z7VidEKvXL5iB_PXs2zm9LLe9bPgSklde0z";s:5:"block";i:40470016;s:4:"time";s:19:"2024-09-18 10:51:06";s:8:"sort_key";i:1;}i:2;a:8:{s:11:"transaction";s:64:"2eb5cdd0f9d4b600ca3d9d610e6bb9830e7c130431277a93f7f5672b9a24e73b";s:7:"address";s:48:"EQB3ncyBUTjZUA5EnFKR5_EnOMI9V1tTEAAPaiU71gc4TiUt";s:6:"effect";s:14:"-8569200000000";s:13:"extra_indexed";s:29:"(0,a800000000000000,45734519)";s:8:"currency";s:48:"EQCV5dXNrWVU1z7VidEKvXL5iB_PXs2zm9LLe9bPgSklde0z";s:5:"block";i:40470016;s:4:"time";s:19:"2024-09-18 10:51:06";s:8:"sort_key";i:2;}i:3;a:8:{s:11:"transaction";s:64:"2eb5cdd0f9d4b600ca3d9d610e6bb9830e7c130431277a93f7f5672b9a24e73b";s:7:"address";s:48:"EQDMj00WZdlpEHY1_IQEkfgwanwLs6RhLnu9lxYYj3qlxCQX";s:6:"effect";s:13:"8569200000000";s:13:"extra_indexed";s:29:"(0,a800000000000000,45734519)";s:8:"currency";s:48:"EQCV5dXNrWVU1z7VidEKvXL5iB_PXs2zm9LLe9bPgSklde0z";s:5:"block";i:40470016;s:4:"time";s:19:"2024-09-18 10:51:06";s:8:"sort_key";i:3;}i:4;a:8:{s:11:"transaction";s:64:"815884a27116be0bc704bf64d4669d89e803012bbad1ef4b5be57d3472239697";s:7:"address";s:48:"EQDCTl1v-TPqf-X26w3JVqYokkAYsMz6BQK5Hkd7NeYWYjF8";s:6:"effect";s:14:"-5431500000000";s:13:"extra_indexed";s:29:"(0,a800000000000000,45734519)";s:8:"currency";s:48:"EQCV5dXNrWVU1z7VidEKvXL5iB_PXs2zm9LLe9bPgSklde0z";s:5:"block";i:40470016;s:4:"time";s:19:"2024-09-18 10:51:06";s:8:"sort_key";i:4;}i:5;a:8:{s:11:"transaction";s:64:"815884a27116be0bc704bf64d4669d89e803012bbad1ef4b5be57d3472239697";s:7:"address";s:48:"EQASNZzIszZwju0GPyu7yCYOLuR2_vUYcU11G--PlkVLN69w";s:6:"effect";s:13:"5431500000000";s:13:"extra_indexed";s:29:"(0,a800000000000000,45734519)";s:8:"currency";s:48:"EQCV5dXNrWVU1z7VidEKvXL5iB_PXs2zm9LLe9bPgSklde0z";s:5:"block";i:40470016;s:4:"time";s:19:"2024-09-18 10:51:06";s:8:"sort_key";i:5;}}s:10:"currencies";a:1:{i:0;a:4:{s:2:"id";s:48:"EQCV5dXNrWVU1z7VidEKvXL5iB_PXs2zm9LLe9bPgSklde0z";s:4:"name";s:6:"NOT-AI";s:6:"symbol";s:5:"NOTAI";s:8:"decimals";s:1:"9";}}}'],
        ];
    }
}
