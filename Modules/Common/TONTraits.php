<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023-2024 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  Common functions for TON modules (JettonModule, NFJettonModule)  */


trait TONTraits
{
    public function inquire_latest_block()
    {
        $result = requester_single(
            $this->select_node(),
            endpoint: 'get_blocks/by_master_height',
            params: [
                'args' => [
                    'latest',
                    false
                ]
            ],
            timeout: $this->timeout);

        return (int)$result['seqno'];
    }

    public function ensure_block($block_id, $break_on_first = false)
    {
        $block = requester_single(
            $this->select_node(),
            endpoint: 'get_blocks/by_master_height',
            params: [
                'args' => [
                    $block_id,
                    false
                ]
            ],
            timeout: $this->timeout);

        $this->block_hash = strtolower($block['filehash'] . $block['roothash']);
        $this->block_time = date('Y-m-d H:i:s', (int)$block['timestamp']);
    }

    public function generate_event_pair($tx, $src, $dst, $amt, $lt_sort, $intra_tx, $extra, $extra_indexed)
    {
        $sub = [
            'transaction' => $tx,
            'address' => $src,
            'effect' => '-' . $amt,
            'lt_sort' => $lt_sort,
            'intra_tx' => 2 * ($intra_tx),
            'extra' => $extra,
            'extra_indexed' => $extra_indexed
        ];
        $add = [
            'transaction' => $tx,
            'address' => $dst,
            'effect' => $amt,
            'lt_sort' => $lt_sort,
            'intra_tx' => 2 * ($intra_tx) + 1,
            'extra' => $extra,
            'extra_indexed' => $extra_indexed
        ];
        return [$sub, $add];
    }

}
