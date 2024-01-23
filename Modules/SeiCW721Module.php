<?php declare(strict_types=1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the CW721 Sei module. */

final class SeiCW721Module extends CosmosCW721Module implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'sei';
        $this->module = 'sei-cw-721';
        $this->is_main = false;
        $this->first_block_date = '2022-05-28';

        // Cosmos-specific
        $this->extra_features = [CosmosSpecialFeatures::HasNotCodeField];

        $this->tests = [
            // Transfer
            ['block' => 53278074, 'result' => 'a:2:{s:6:"events";a:2:{i:0;a:9:{s:11:"transaction";s:64:"e9be09575e6410ee47c4681f2830b852529d1e6cf2456ea2bc9698bf3c13c810";s:8:"sort_key";i:0;s:7:"address";s:62:"sei1pdwlx9h8nc3fp6073mweug654wfkxjaelgkum0a9wtsktwuydw5sduczvz";s:8:"currency";s:62:"sei12ne7qtmdwd0j03t9t5es8md66wq4e5xg9neladrsag8fx3y89rcs5m2xaj";s:6:"effect";s:2:"-1";s:6:"failed";s:1:"f";s:5:"extra";s:19:"C98B68nM9qDNMKRd6Pu";s:5:"block";i:53278074;s:4:"time";s:19:"2024-01-22 12:11:29";}i:1;a:9:{s:11:"transaction";s:64:"e9be09575e6410ee47c4681f2830b852529d1e6cf2456ea2bc9698bf3c13c810";s:8:"sort_key";i:1;s:7:"address";s:42:"sei1np5c83mjqn5fhqcdsrt9gztakt2mkwdhzdp95h";s:8:"currency";s:62:"sei12ne7qtmdwd0j03t9t5es8md66wq4e5xg9neladrsag8fx3y89rcs5m2xaj";s:6:"effect";s:1:"1";s:6:"failed";s:1:"f";s:5:"extra";s:19:"C98B68nM9qDNMKRd6Pu";s:5:"block";i:53278074;s:4:"time";s:19:"2024-01-22 12:11:29";}}s:10:"currencies";a:1:{i:0;a:3:{s:2:"id";s:62:"sei12ne7qtmdwd0j03t9t5es8md66wq4e5xg9neladrsag8fx3y89rcs5m2xaj";s:4:"name";s:13:"Dagora Minter";s:6:"symbol";s:2:"DM";}}}']
        ];
    }
}
