<?php declare(strict_types = 1);

/*  Copyright (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com
 *  Distributed under the MIT software license, see the accompanying file LICENSE.md  */

final class RippleTokenModule extends RippleLikeTokenModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'ripple';
        $this->module = 'ripple-token';
        $this->is_main = false;
        $this->first_block_date = '2013-01-01';
        $this->first_block_id = 32570;

    }
}