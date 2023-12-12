<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the main Cardano module which processes UTXO transfers only. See CardanoLikeNativeTokensModule for details.  */

final class CardanoNativeTokensModule extends CardanoLikeNativeTokensModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'cardano';
        $this->module = 'cardano-native-tokens';
        $this->is_main = false;
        $this->first_block_id = 1;
        $this->first_block_date = '2017-09-23';
    }
}
