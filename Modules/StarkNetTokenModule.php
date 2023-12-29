<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes token transfers in StarkNet. It requires either a geth or */

final class StarkNetTokenModule extends StarkNetLikeTokenModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'starknet';
        $this->module = 'starknet-token';
        $this->is_main = false;
        $this->first_block_date = '2021-11-16';
        $this->first_block_id = 0;
    }
}
