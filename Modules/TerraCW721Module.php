<?php declare(strict_types=1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the CW721 Terra2.0 module. */

final class TerraCW721Module extends CosmosCW721Module implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'terra';
        $this->module = 'terra-cw-721';
        $this->is_main = false;
        $this->first_block_date = '2022-05-28';

        // Cosmos-specific
        $this->extra_features = [CosmosSpecialFeatures::HasDecodedValues];
    }
}
