<?php declare(strict_types=1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the TokenFactory Sei module. */

final class SeiTokenFactoryModule extends CosmosTokenFactoryModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'sei';
        $this->module = 'sei-token-factory';
        $this->is_main = false;
        $this->first_block_date = '2022-05-28';

        // Cosmos-specific
        $this->cosmos_coin_events_fork = 0;
        $this->extra_features = [CosmosSpecialFeatures::HasNotCodeField];

        $this->tests = [
            ['block' => 53277885, 'result' => 'a:2:{s:6:"events";a:6:{i:0;a:9:{s:11:"transaction";s:64:"8983f3d421d5ae995bad5ebab3ba3ad135cd82e8992c44504ea62e8b729f566d";s:8:"sort_key";i:0;s:7:"address";s:8:"the-void";s:8:"currency";s:75:"factory_sei1e3gttzq5e5k49f9f5gzvrl0rltlav65xu6p9xc0aj7e84lantdjqp7cncc_isei";s:6:"effect";s:9:"-10978280";s:6:"failed";s:1:"f";s:5:"extra";N;s:5:"block";i:53277885;s:4:"time";s:19:"2024-01-22 12:10:09";}i:1;a:9:{s:11:"transaction";s:64:"8983f3d421d5ae995bad5ebab3ba3ad135cd82e8992c44504ea62e8b729f566d";s:8:"sort_key";i:1;s:7:"address";s:42:"sei19ejy8n9qsectrf4semdp9cpknflld0j6svvmtq";s:8:"currency";s:75:"factory_sei1e3gttzq5e5k49f9f5gzvrl0rltlav65xu6p9xc0aj7e84lantdjqp7cncc_isei";s:6:"effect";s:8:"10978280";s:6:"failed";s:1:"f";s:5:"extra";N;s:5:"block";i:53277885;s:4:"time";s:19:"2024-01-22 12:10:09";}i:2;a:9:{s:11:"transaction";s:64:"8983f3d421d5ae995bad5ebab3ba3ad135cd82e8992c44504ea62e8b729f566d";s:8:"sort_key";i:2;s:7:"address";s:42:"sei19ejy8n9qsectrf4semdp9cpknflld0j6svvmtq";s:8:"currency";s:75:"factory_sei1e3gttzq5e5k49f9f5gzvrl0rltlav65xu6p9xc0aj7e84lantdjqp7cncc_isei";s:6:"effect";s:9:"-10978280";s:6:"failed";s:1:"f";s:5:"extra";N;s:5:"block";i:53277885;s:4:"time";s:19:"2024-01-22 12:10:09";}i:3;a:9:{s:11:"transaction";s:64:"8983f3d421d5ae995bad5ebab3ba3ad135cd82e8992c44504ea62e8b729f566d";s:8:"sort_key";i:3;s:7:"address";s:62:"sei1e3gttzq5e5k49f9f5gzvrl0rltlav65xu6p9xc0aj7e84lantdjqp7cncc";s:8:"currency";s:75:"factory_sei1e3gttzq5e5k49f9f5gzvrl0rltlav65xu6p9xc0aj7e84lantdjqp7cncc_isei";s:6:"effect";s:8:"10978280";s:6:"failed";s:1:"f";s:5:"extra";N;s:5:"block";i:53277885;s:4:"time";s:19:"2024-01-22 12:10:09";}i:4;a:9:{s:11:"transaction";s:64:"8983f3d421d5ae995bad5ebab3ba3ad135cd82e8992c44504ea62e8b729f566d";s:8:"sort_key";i:4;s:7:"address";s:62:"sei1e3gttzq5e5k49f9f5gzvrl0rltlav65xu6p9xc0aj7e84lantdjqp7cncc";s:8:"currency";s:75:"factory_sei1e3gttzq5e5k49f9f5gzvrl0rltlav65xu6p9xc0aj7e84lantdjqp7cncc_isei";s:6:"effect";s:9:"-10978280";s:6:"failed";s:1:"f";s:5:"extra";N;s:5:"block";i:53277885;s:4:"time";s:19:"2024-01-22 12:10:09";}i:5;a:9:{s:11:"transaction";s:64:"8983f3d421d5ae995bad5ebab3ba3ad135cd82e8992c44504ea62e8b729f566d";s:8:"sort_key";i:5;s:7:"address";s:42:"sei1tr5x3q04prra3tu0fsan9da7v98ve8eqqwv2tn";s:8:"currency";s:75:"factory_sei1e3gttzq5e5k49f9f5gzvrl0rltlav65xu6p9xc0aj7e84lantdjqp7cncc_isei";s:6:"effect";s:8:"10978280";s:6:"failed";s:1:"f";s:5:"extra";N;s:5:"block";i:53277885;s:4:"time";s:19:"2024-01-22 12:10:09";}}s:10:"currencies";a:1:{i:0;a:3:{s:2:"id";s:75:"factory_sei1e3gttzq5e5k49f9f5gzvrl0rltlav65xu6p9xc0aj7e84lantdjqp7cncc_isei";s:4:"name";s:4:"isei";s:8:"decimals";i:6;}}}']
        ];
    }
}
