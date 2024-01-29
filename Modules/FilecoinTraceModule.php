<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the internal Filecoin module. */

final class FilecoinTraceModule extends FilecoinLikeTraceModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'filecoin';
        $this->module = 'filecoin-trace';
        $this->is_main = false;
        $this->first_block_date = '2020-08-25';
        $this->complements = 'filecoin-main';
    }
}
