<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023-2024 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the main Base module. It requires a geth node to run.  */

final class BaseMainModule extends EVMMainModule implements Module, BalanceSpecial, TransactionSpecials, AddressSpecials
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'base';
        $this->module = 'base-main';
        $this->is_main = true;
        $this->first_block_date = '2023-06-15';
        $this->first_block_id = 0;
        $this->currency = 'ethereum';
        $this->currency_details = ['name' => 'Ethereum', 'symbol' => 'ETH', 'decimals' => 18, 'description' => null];
        $this->mempool_implemented = false; // Unlike other EVMMainModule heirs, Base doesn't implement mempool

        // EVMMainModule
        $this->evm_implementation = EVMImplementation::geth;
        $this->extra_features = [EVMSpecialFeatures::HasSystemTransactions, EVMSpecialFeatures::OPStack]; // Base is a fork of Optimism, so it has the same special txs
        $this->reward_function = function($block_id)
        {
            return '0';
        };
        $this->l1_fee_vault = '0x420000000000000000000000000000000000001A';  // https://github.com/ethereum-optimism/op-geth/blob/c6ea6fa09d4e7df6d1ca6b2d32bcb139f021b1e2/params/protocol_params.go#L29 as it uses the same code base
        $this->base_fee_recipient = '0x4200000000000000000000000000000000000019';
    }
}
