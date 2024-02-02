<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the XCM Acala module. */

final class AcalaXCMModule extends SubstrateXCMModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'acala';
        $this->module = 'acala-xcm';
        $this->is_main = false;
        $this->first_block_date = '2021-12-18';

        // Substrait XCM specific
        $this->native_asset_id = 'native-ACA';
    }
}
