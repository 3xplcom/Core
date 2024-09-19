<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module works with the TEP-74 standard, see
 *  https://github.com/ton-blockchain/TEPs/blob/master/text/0074-jettons-standard.md */  

final class TONNFTModule extends TONLikeTokensModule implements Module
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
            ['block' => 40470001, 'result' => 'a:2:{s:6:"events";a:10:{i:0;a:8:{s:11:"transaction";s:64:"21178bd97fc9185a6622988af7b9de86ad67cbef4fe2c5d2d7fe00e6cfb26547";s:7:"address";s:48:"EQAiZeV2VfsPksPAlcGGwOHUGAKnFs27w3GDpV9T45PMIFkH";s:6:"effect";s:2:"-1";s:13:"extra_indexed";s:29:"(0,7000000000000000,45754673)";s:8:"currency";s:48:"EQAiZeV2VfsPksPAlcGGwOHUGAKnFs27w3GDpV9T45PMIFkH";s:5:"block";i:40470001;s:4:"time";s:19:"2024-09-18 10:50:08";s:8:"sort_key";i:0;}i:1;a:8:{s:11:"transaction";s:64:"21178bd97fc9185a6622988af7b9de86ad67cbef4fe2c5d2d7fe00e6cfb26547";s:7:"address";s:48:"EQBpHoCRURumt61BbOYbgb5JO6Y1-zwdHDTCgop4Eqsez9DJ";s:6:"effect";s:1:"1";s:13:"extra_indexed";s:29:"(0,7000000000000000,45754673)";s:8:"currency";s:48:"EQAiZeV2VfsPksPAlcGGwOHUGAKnFs27w3GDpV9T45PMIFkH";s:5:"block";i:40470001;s:4:"time";s:19:"2024-09-18 10:50:08";s:8:"sort_key";i:1;}i:2;a:8:{s:11:"transaction";s:64:"3717ec3fbed8e720a213579e77086a6b6458a94916dfd798e17a11f9373bfd6b";s:7:"address";s:48:"EQCA14o1-VWhS2efqoh_9M1b_A9DtKTuoqfmkn83AbJzwnPi";s:6:"effect";s:2:"-1";s:13:"extra_indexed";s:29:"(0,7000000000000000,45754673)";s:8:"currency";s:48:"EQBte36QWZQuZE1INOdvKahaGjDIC3oM99YuYcAcJhvS_4LZ";s:5:"block";i:40470001;s:4:"time";s:19:"2024-09-18 10:50:08";s:8:"sort_key";i:2;}i:3;a:8:{s:11:"transaction";s:64:"3717ec3fbed8e720a213579e77086a6b6458a94916dfd798e17a11f9373bfd6b";s:7:"address";s:48:"EQBte36QWZQuZE1INOdvKahaGjDIC3oM99YuYcAcJhvS_4LZ";s:6:"effect";s:1:"1";s:13:"extra_indexed";s:29:"(0,7000000000000000,45754673)";s:8:"currency";s:48:"EQBte36QWZQuZE1INOdvKahaGjDIC3oM99YuYcAcJhvS_4LZ";s:5:"block";i:40470001;s:4:"time";s:19:"2024-09-18 10:50:08";s:8:"sort_key";i:3;}i:4;a:8:{s:11:"transaction";s:64:"477f2a1336c195aedc4cc0443afecd10d97ed418869cdbfd17153a59a667223f";s:7:"address";s:48:"EQCbrTGaRq4PlAbSuse1HWDdRTrOcBqwLIq4k1spxXza4IOj";s:6:"effect";s:2:"-1";s:13:"extra_indexed";s:29:"(0,1000000000000000,45755983)";s:8:"currency";s:48:"EQCbrTGaRq4PlAbSuse1HWDdRTrOcBqwLIq4k1spxXza4IOj";s:5:"block";i:40470001;s:4:"time";s:19:"2024-09-18 10:50:08";s:8:"sort_key";i:4;}i:5;a:8:{s:11:"transaction";s:64:"477f2a1336c195aedc4cc0443afecd10d97ed418869cdbfd17153a59a667223f";s:7:"address";s:48:"EQAJ-QU4xFrQRr1JCtQS7zAgCOvVDl5tkBvgwbAGOGiRd9MO";s:6:"effect";s:1:"1";s:13:"extra_indexed";s:29:"(0,1000000000000000,45755983)";s:8:"currency";s:48:"EQCbrTGaRq4PlAbSuse1HWDdRTrOcBqwLIq4k1spxXza4IOj";s:5:"block";i:40470001;s:4:"time";s:19:"2024-09-18 10:50:08";s:8:"sort_key";i:5;}i:6;a:8:{s:11:"transaction";s:64:"873e6a69b8ddf3f1604159080d9ba6f8dcb2d9ed885943506335040450dac9c1";s:7:"address";s:48:"EQDNU5WByVGCoG8EGJMT5wFH2rikzxFdpBM4WVR_abr51JOL";s:6:"effect";s:2:"-1";s:13:"extra_indexed";s:29:"(0,1000000000000000,45755983)";s:8:"currency";s:48:"EQABEJDNwHqBnCc5TwHBRWY6Ynnmj_-dREOQDe8ybRKRfUVx";s:5:"block";i:40470001;s:4:"time";s:19:"2024-09-18 10:50:08";s:8:"sort_key";i:6;}i:7;a:8:{s:11:"transaction";s:64:"873e6a69b8ddf3f1604159080d9ba6f8dcb2d9ed885943506335040450dac9c1";s:7:"address";s:48:"EQCcpi3jRZqpTfxmatIMZSKeB3lKFPTCrqiNoSlLroanRc_F";s:6:"effect";s:1:"1";s:13:"extra_indexed";s:29:"(0,1000000000000000,45755983)";s:8:"currency";s:48:"EQABEJDNwHqBnCc5TwHBRWY6Ynnmj_-dREOQDe8ybRKRfUVx";s:5:"block";i:40470001;s:4:"time";s:19:"2024-09-18 10:50:08";s:8:"sort_key";i:7;}i:8;a:8:{s:11:"transaction";s:64:"9712b2343d3e790cee80d886887a3c1c126013506863e6dc3361fb962e12a219";s:7:"address";s:48:"EQDYnoGIvZqeKSN2ExwPAKKXhLJxv3Y17J8wD_uGb3WREGqG";s:6:"effect";s:2:"-1";s:13:"extra_indexed";s:29:"(0,9000000000000000,45737433)";s:8:"currency";s:48:"EQCIQtrQTjYE0K5BxbNHurlTTKVFaxR0VXbViumXPF5IZwWH";s:5:"block";i:40470001;s:4:"time";s:19:"2024-09-18 10:50:08";s:8:"sort_key";i:8;}i:9;a:8:{s:11:"transaction";s:64:"9712b2343d3e790cee80d886887a3c1c126013506863e6dc3361fb962e12a219";s:7:"address";s:48:"EQAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAM9c";s:6:"effect";s:1:"1";s:13:"extra_indexed";s:29:"(0,9000000000000000,45737433)";s:8:"currency";s:48:"EQCIQtrQTjYE0K5BxbNHurlTTKVFaxR0VXbViumXPF5IZwWH";s:5:"block";i:40470001;s:4:"time";s:19:"2024-09-18 10:50:08";s:8:"sort_key";i:9;}}s:10:"currencies";a:3:{i:0;a:4:{s:2:"id";s:48:"EQABEJDNwHqBnCc5TwHBRWY6Ynnmj_-dREOQDe8ybRKRfUVx";s:4:"name";s:30:"Bump Spaceship Modules pack. 2";s:6:"symbol";s:0:"";s:8:"decimals";s:1:"0";}i:1;a:4:{s:2:"id";s:48:"EQBte36QWZQuZE1INOdvKahaGjDIC3oM99YuYcAcJhvS_4LZ";s:4:"name";s:18:"Telegram Usernames";s:6:"symbol";s:0:"";s:8:"decimals";s:1:"0";}i:2;a:4:{s:2:"id";s:48:"EQCIQtrQTjYE0K5BxbNHurlTTKVFaxR0VXbViumXPF5IZwWH";s:4:"name";s:10:"BeeHarvest";s:6:"symbol";s:0:"";s:8:"decimals";s:1:"0";}}}'],
        ];
    }
}
