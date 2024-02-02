<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the XCM Kusama module. */

final class KusamaXCMModule extends SubstrateXCMMainModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'kusama';
        $this->module = 'kusama-xcm';
        $this->is_main = false;
        $this->complements = 'kusama-main';
        $this->first_block_date = '2019-11-28';
    }
}
