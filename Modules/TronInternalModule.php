<?php declare(strict_types=1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the main Tron module. It requires java-tron node to run */

final class TronInternalModule extends TVMInternalModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'tron';
        $this->module = 'tron-internal';
        $this->complements = 'tron-main';
        $this->is_main = false;
        $this->first_block_date = '2018-06-25';
    }
}
