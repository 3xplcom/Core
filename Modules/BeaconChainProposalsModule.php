<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the Proposals module for Beacon Chain. It requires a Prysm or a Lighthouse node to run.  */

final class BeaconChainProposalsModule extends BeaconChainLikeProposalsModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'beacon-chain';
        $this->module = 'beacon-chain-proposals';
        $this->complements = 'beacon-chain-deposits';
        $this->is_main = false;
        $this->first_block_date = '2020-12-01';
        $this->first_block_id = 0;

        // BeaconChainLikeModule
        $this->chain_config = [
            'BELLATRIX_FORK_EPOCH' => 144896,
            'SLOT_PER_EPOCH' => 32,
            'DELAY' => 2,
        ];
    }
}
