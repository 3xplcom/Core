<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module works with the TEP-74 standard, see
 *  https://github.com/ton-blockchain/TEPs/blob/master/text/0074-jettons-standard.md */  

final class TONNFTModule extends TONLikeTokensModule implements Module, MultipleBalanceSpecial
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'ton';
        $this->module = 'ton-nft';
        $this->is_main = false;
        $this->first_block_date = '2019-11-15';
        $this->first_block_id = 1;

        // TONLikeTokensModule
        $this->workchain = '0'; // BaseChain
        $this->currency_type = CurrencyType::NFT;

        // Tests
        $this->tests = [
            ['block' => 40470019, 'result' => 'a:2:{s:6:"events";a:10:{i:0;a:7:{s:11:"transaction";s:64:"27b86bd31997a933857649f84a9d1becc268644d3aaf8e2cb006625e395298e9";s:7:"address";s:48:"EQDMp5nZQsqZOKWk8Q3SXmDEOmO7-_D6DP_jHHeSRJe32Y2j";s:6:"effect";s:2:"-1";s:8:"currency";s:48:"EQDMp5nZQsqZOKWk8Q3SXmDEOmO7-_D6DP_jHHeSRJe32Y2j";s:5:"block";i:40470019;s:4:"time";s:19:"2024-09-18 10:51:22";s:8:"sort_key";i:0;}i:1;a:7:{s:11:"transaction";s:64:"27b86bd31997a933857649f84a9d1becc268644d3aaf8e2cb006625e395298e9";s:7:"address";s:48:"EQDqaeryhqu_kKxL0SQ8pNNYkrzeqx11zIgdDLPtd79S6jIR";s:6:"effect";s:1:"1";s:8:"currency";s:48:"EQDMp5nZQsqZOKWk8Q3SXmDEOmO7-_D6DP_jHHeSRJe32Y2j";s:5:"block";i:40470019;s:4:"time";s:19:"2024-09-18 10:51:22";s:8:"sort_key";i:1;}i:2;a:7:{s:11:"transaction";s:64:"ba80008c916ab46fafd6b5e98f8dd0391a8b43994eb80850e8c08519f589082f";s:7:"address";s:48:"EQCwF7-OQiHBxKePiBFOAWwzHDTFHkJR9likOqTYFQc08OmS";s:6:"effect";s:2:"-1";s:8:"currency";s:48:"EQCVNxC9hPaC6b5nZz-GVy_psUN59Pocj2lXI44jUa0Q6rd8";s:5:"block";i:40470019;s:4:"time";s:19:"2024-09-18 10:51:22";s:8:"sort_key";i:2;}i:3;a:7:{s:11:"transaction";s:64:"ba80008c916ab46fafd6b5e98f8dd0391a8b43994eb80850e8c08519f589082f";s:7:"address";s:48:"EQDX5lXoYcWNwVd1TU4sQ-VsBSOB_NZeg-St9yrRWHQA4rWk";s:6:"effect";s:1:"1";s:8:"currency";s:48:"EQCVNxC9hPaC6b5nZz-GVy_psUN59Pocj2lXI44jUa0Q6rd8";s:5:"block";i:40470019;s:4:"time";s:19:"2024-09-18 10:51:22";s:8:"sort_key";i:3;}i:4;a:7:{s:11:"transaction";s:64:"c6529e2a71c76fa1ae1a5e0af0f0cd06d2f612deebc84987b6dc55227496d677";s:7:"address";s:48:"EQBrnSNI6soNcKlWjXl52APLwWnSrPdw0KIS9B99_xqo2lUf";s:6:"effect";s:2:"-1";s:8:"currency";s:48:"EQD6vxgaarq7nP6tW1sJHMQSzOTWV_PsPcOvck6coW_n3OnX";s:5:"block";i:40470019;s:4:"time";s:19:"2024-09-18 10:51:22";s:8:"sort_key";i:4;}i:5;a:7:{s:11:"transaction";s:64:"c6529e2a71c76fa1ae1a5e0af0f0cd06d2f612deebc84987b6dc55227496d677";s:7:"address";s:48:"EQDG3Pq7UHHzVEOTlj-lEHvOmdK8fDVKD6SyznWL8oqBpNvi";s:6:"effect";s:1:"1";s:8:"currency";s:48:"EQD6vxgaarq7nP6tW1sJHMQSzOTWV_PsPcOvck6coW_n3OnX";s:5:"block";i:40470019;s:4:"time";s:19:"2024-09-18 10:51:22";s:8:"sort_key";i:5;}i:6;a:7:{s:11:"transaction";s:64:"cfd87ca51cc5661dd66ecbfc1a9248230f0da4ab993feeaeb2e1acaf6ae8e4f1";s:7:"address";s:48:"EQDMp5nZQsqZOKWk8Q3SXmDEOmO7-_D6DP_jHHeSRJe32Y2j";s:6:"effect";s:2:"-1";s:8:"currency";s:48:"EQDMp5nZQsqZOKWk8Q3SXmDEOmO7-_D6DP_jHHeSRJe32Y2j";s:5:"block";i:40470019;s:4:"time";s:19:"2024-09-18 10:51:22";s:8:"sort_key";i:6;}i:7;a:7:{s:11:"transaction";s:64:"cfd87ca51cc5661dd66ecbfc1a9248230f0da4ab993feeaeb2e1acaf6ae8e4f1";s:7:"address";s:48:"EQCaafehYAv8Fy7po2dQoVt9JfepP4A5s9AY0_YKhbTt1fnL";s:6:"effect";s:1:"1";s:8:"currency";s:48:"EQDMp5nZQsqZOKWk8Q3SXmDEOmO7-_D6DP_jHHeSRJe32Y2j";s:5:"block";i:40470019;s:4:"time";s:19:"2024-09-18 10:51:22";s:8:"sort_key";i:7;}i:8;a:7:{s:11:"transaction";s:64:"702f252dee825cb03e82db8581a80341c17b596a4b284e19d60b35b8f1804911";s:7:"address";s:48:"EQAiZeV2VfsPksPAlcGGwOHUGAKnFs27w3GDpV9T45PMIFkH";s:6:"effect";s:2:"-1";s:8:"currency";s:48:"EQAiZeV2VfsPksPAlcGGwOHUGAKnFs27w3GDpV9T45PMIFkH";s:5:"block";i:40470019;s:4:"time";s:19:"2024-09-18 10:51:22";s:8:"sort_key";i:8;}i:9;a:7:{s:11:"transaction";s:64:"702f252dee825cb03e82db8581a80341c17b596a4b284e19d60b35b8f1804911";s:7:"address";s:48:"EQApQ5NY2iROFhV8hwB3u4se4c6oAmW20G60XP4rw5hiiEJl";s:6:"effect";s:1:"1";s:8:"currency";s:48:"EQAiZeV2VfsPksPAlcGGwOHUGAKnFs27w3GDpV9T45PMIFkH";s:5:"block";i:40470019;s:4:"time";s:19:"2024-09-18 10:51:22";s:8:"sort_key";i:9;}}s:10:"currencies";a:0:{}}'],
        ];
    }
}
