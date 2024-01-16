<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  Common functions for Beacon Chain modules  */

trait BeaconChainLikeTraits
{
    public function inquire_latest_block()
    {
        $result = requester_single($this->select_node(),
            endpoint: 'eth/v1/beacon/headers',
            timeout: $this->timeout,
            result_in: 'data');

        return intdiv((int)$result[0]['header']['message']['slot'], $this->chain_config['SLOT_PER_EPOCH']) - $this->chain_config['DELAY'];
    }

    public function ensure_block($block, $break_on_first = false)
    {
        $hashes = [];

        $block_start = $block * $this->chain_config['SLOT_PER_EPOCH'];
        $block_end = $block_start + ($this->chain_config['SLOT_PER_EPOCH'] - 1);

        foreach ($this->nodes as $node)
        {
            $multi_curl = [];

            for ($i = $block_start; $i <= $block_end; $i++)
            {
                $multi_curl[] = requester_multi_prepare($node,
                    endpoint: "eth/v1/beacon/headers/{$i}",
                    timeout: $this->timeout,
                );
            }

            $curl_results = requester_multi(
                $multi_curl,
                limit: envm($this->module, 'REQUESTER_THREADS'),
                timeout: $this->timeout,
                valid_codes: [200, 404],
            );

            foreach ($curl_results as $result)
            {
                $hash_result = requester_multi_process($result);

                if (isset($hash_result['code']) && $hash_result['code'] === '404')
                    continue;
                elseif (isset($hash_result['code']))
                    throw new ModuleError('Unexpected response code');

                $root = $hash_result['data']['root'];
                $slot = $hash_result['data']['header']['message']['slot'];

                $hashes_res[$slot] = $root;
            }

            ksort($hashes_res);
            $hash = join($hashes_res);
            $hashes[] = $hash;

            if ($break_on_first)
                break;
        }

        if (isset($hashes[1]))
            for ($i = 1; $i < count($hashes); $i++)
                if ($hashes[0] !== $hashes[$i])
                    throw new ConsensusException("ensure_block(block_id: {$block}): no consensus");

        $hashes[0] = str_replace('0x', '', $hashes[0]);

        $this->block_hash = $hashes[0];
        $this->block_id = $block;
    }   

    private function get_epoch_time($block, $slots) 
    { 
        $block_time = 0;
        $this_slot_times = array_reverse($slots);

        foreach ($this_slot_times as $time)
        {
            if (!is_null($time))
            {
                $block_time = $time;
                break;
            }
        }

        if ($block < $this->chain_config['BELLATRIX_FORK_EPOCH'] || $block_time === 0)
        {   // 1606824023 - zero epoch time, 12 - seconds between slots, 384 = 32 (amount of slots in epoch) * 12
            $this->block_time = date('Y-m-d H:i:s', ($block + 1) * 384 + 1606824023 - 12);
            return;
        }

        $this->block_time = date('Y-m-d H:i:s', $block_time);
    }
}
