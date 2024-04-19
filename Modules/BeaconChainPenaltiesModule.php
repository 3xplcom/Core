<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the Penalties module for Beacon Chain. It requires a Prysm or a Lighthouse node to run.  */

final class BeaconChainPenaltiesModule extends BeaconChainLikePenaltiesModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'beacon-chain';
        $this->module = 'beacon-chain-penalties';
        $this->complements = 'beacon-chain-deposits';
        $this->is_main = false;
        $this->first_block_date = '2020-12-01';
        $this->first_block_id = 0;

        // BeaconChainLikeModule
        $this->chain_config = [
            'PHASE0_FORK_EPOCH' => 0,
            'ALTAIR_FORK_EPOCH' => 74240,
            'BELLATRIX_FORK_EPOCH' => 144896,
            'MIN_SLASHING_PENALTY_QUOTIENT' => 128,
            'MIN_SLASHING_PENALTY_QUOTIENT_ALTAIR' => 64,
            'MIN_SLASHING_PENALTY_QUOTIENT_BELLATRIX' => 32,
            'WHISTLEBLOWER_REWARD_QUOTIENT' => 512,
            'SLOT_PER_EPOCH' => 32,
            'DELAY' => 2,
            ];
    }
}
