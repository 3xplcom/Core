<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the Tokens Acala module. */

final class AcalaTokensModule extends SubstrateTokensModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'acala';
        $this->module = 'acala-tokens';
        $this->is_main = false;
        $this->first_block_date = '2021-12-18';

        // Tokens-specific
        $this->native_token_id = 'native-ACA';
    }
}
