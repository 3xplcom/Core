<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  Common Filecoin functions and enums  */

enum FilecoinSpecialTransactions: string
{
    case FeeToMiner = 'f';
    case FeeToBurn = 'b';
    case BlockReward = 'r';
}

trait FilecoinTraits
{
    public function inquire_latest_block()
    {
        $header = requester_single($this->select_node(),
            params: [
                'method'  => 'Filecoin.ChainHead',
                'params'  => [],
                'id'      => 0,
                'jsonrpc' => '2.0',
            ],
            result_in: 'result',
            timeout: $this->timeout
        );

        // At latest tipset may be more than one block but all have the same height
        // It also may have 0 blocks
        if (count($header['Blocks']) === 0)
            throw new ModuleException("Zero blocks array in tipset.");

        $block_height = (int)$header['Blocks'][0]['Height'];
        // Additional checks all blocks have same height
        foreach ($header['Blocks'] as $block_info)
        {
            if ((int)$block_info['Height'] !== $block_height)
                throw new ModuleException("Blocks height mismatch");
        }

        // Latest block is Head - 1 which already have all Receipts and final messages order
        return (int)$block_height - 1;
    }

    public function ensure_block($block_id, $break_on_first = false)
    {
        $multi_curl = [];
        foreach ($this->nodes as $node)
        {
            $multi_curl[] = requester_multi_prepare(
                $node,
                params: [
                    'method'  => 'Filecoin.ChainGetTipSetByHeight',
                    'params'  => [
                        $block_id,
                        []
                    ],
                    'id'      => 0,
                    'jsonrpc' => '2.0',
                ],
                timeout: $this->timeout
            );
        }

        try
        {
            $curl_results = requester_multi($multi_curl, limit: count($this->nodes), timeout: $this->timeout);
        }
        catch (RequesterException $e)
        {
            throw new RequesterException("ensure_block(block_id: {$block_id}): no connection, previously: " . $e->getMessage());
        }

        if (count($curl_results) !== 0)
        {
            $result = requester_multi_process($curl_results[0], result_in: 'result');

            $this->block_hash = $this->make_tipset_hash($block_id, $result['Cids']);

            $block_time = $result['Blocks'][0]['Timestamp'];
            $this->ensure_blocks_timestamp($block_time, $result['Blocks']);
            $this->block_time = date('Y-m-d H:i:s', (int)$block_time);

            foreach ($curl_results as $curl_result)
            {
                $result = requester_multi_process($curl_result, result_in: 'result');
                $block_hash = $this->make_tipset_hash($block_id, $result['Cids']);
                if ($block_hash !== $this->block_hash)
                {
                    throw new ConsensusException("ensure_block(block_id: {$block_id}): no consensus");
                }
            }
        }
    }

    // Create unique tipset hash from height and all blocks hashes
    function make_tipset_hash($block_id, array $cids): string
    {
        require_once __DIR__ . '/../../Engine/Crypto/Keccak.php';

        $tipset_full_id = $block_id;
        foreach ($cids as $cid)
        {
            $tipset_full_id = $tipset_full_id . $cid['/'];
        }
        return Keccak9::hash($tipset_full_id, 256);
    }

    // We must ensure that all blocks in tipset have same timestamps
    function ensure_blocks_timestamp(string $block_time, array $blocks)
    {
        foreach ($blocks as $block)
        {
            if ($block_time !== $block['Timestamp'])
                throw new ModuleException("Block timestamp mismatch.");
        }
    }

    // Process all trace subcalls
    function process_trace(array $trace, bool $failed, array &$events, int &$sort_key)
    {
        $calls = $trace['Subcalls'] ?? null;
        if (is_null($calls))
            return;

        foreach ($calls as $call)
        {
            $from = $call['Msg']['From'];
            $to = $call['Msg']['To'];
            $amount = $call['Msg']['Value'];

            if ($amount === '0')
                continue;

            [$sub, $add] = $this->generate_event_pair(null, $from, $to, $amount, $failed, $sort_key);
            if ($from === 'f02') // Reward actor address
            {
                $sub['address'] = 'the-void';
                $sub['extra'] = FilecoinSpecialTransactions::BlockReward->value;
                $add['extra'] = FilecoinSpecialTransactions::BlockReward->value;
            }
            if ($to === 'f099') // burn address
                $add['address'] = 'the-void';
            array_push($events, $sub, $add);

            $this->process_trace($call, $failed, $events, $sort_key);
        }
    }

    // Utility functions

    function generate_event_pair(?string $tx, string $src, string $dst, string $amt, bool $fld, int &$sort_key): array
    {
        $sub = [
            'transaction' => $tx,
            'sort_key' => $sort_key++,
            'address' => $src,
            'effect' => '-' . $amt,
            'failed' => $fld,
            'extra' => null
        ];
        $add = [
            'transaction' => $tx,
            'sort_key' => $sort_key++,
            'address' => $dst,
            'effect' => $amt,
            'failed' => $fld,
            'extra' => null
        ];

        return [$sub, $add];
    }

    // https://github.com/filecoin-project/lotus/blob/23d705e33b8220bbf7aa7c025747ca2972b1e7a9/chain/vm/burn.go#L38
    function compute_gas_overestimation_burn(string $gas_used, string $gas_limit, string $base_fee): string
    {
        if (bccomp($gas_used, '0') === 0)
            return $gas_limit;

        $gas_overuse_num = '11';
        $gas_overuse_denom = '10';

        $over = bcsub($gas_limit, bcdiv(bcmul($gas_overuse_num, $gas_used), $gas_overuse_denom));
        if (bccomp($over, '0') === -1)
            return '0';

        if (bccomp($over, $gas_used) === 1)
            $over = $gas_used;

        $gas_to_burn = bcsub($gas_limit, $gas_used);
        $gas_to_burn = bcmul($gas_to_burn, $over);
        $gas_to_burn = bcdiv($gas_to_burn, $gas_used);
        return bcmul($gas_to_burn, $base_fee);
    }
}
