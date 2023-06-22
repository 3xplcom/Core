<?php declare(strict_types = 1);

/*  Copyright (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com
 *  Distributed under the MIT software license, see the accompanying file LICENSE.md  */

/*  This module processes some basic Polkadot transfers. It requires `subscan-explorer/subscan-essentials` to operate,
 *  see https://github.com/subscan-explorer/subscan-essentials/blob/master/docs/index.md for details. Please note that
 *  Polkadot has extrinsics instead of transactions, and not of them have "transaction hashes". Generally, this module
 *  is still WIP and requires many improvements, thus it's called "minimal" instead of "main". */

abstract class PolkadotLikeMinimalModule extends CoreModule
{
    use UTXOTraits;

    public ?BlockHashFormat $block_hash_format = BlockHashFormat::HexWith0x;
    public ?AddressFormat $address_format = AddressFormat::AlphaNumeric;
    public ?TransactionHashFormat $transaction_hash_format = TransactionHashFormat::HexWith0x; // We process transactions with
    // transfers only, so we don't take extrinsics without hashes into account
    public ?TransactionRenderModel $transaction_render_model = TransactionRenderModel::Even;
    public ?CurrencyFormat $currency_format = CurrencyFormat::Static;
    public ?CurrencyType $currency_type = CurrencyType::FT;
    public ?FeeRenderModel $fee_render_model = FeeRenderModel::ExtraF;
    public ?bool $hidden_values_only = false;

    public ?array $events_table_fields = ['block', 'transaction', 'sort_key', 'time', 'address', 'effect', 'failed', 'extra'];
    public ?array $events_table_nullable_fields = ['extra'];

    public ?ExtraDataModel $extra_data_model = ExtraDataModel::Default;

    public ?bool $should_return_events = true;
    public ?bool $should_return_currencies = false;
    public ?bool $allow_empty_return_events = true;

    public ?bool $mempool_implemented = false;
    public ?bool $forking_implemented = true;

    //

    private ?array $block_data = null;

    //

    final public function pre_initialize()
    {
        $this->version = 1;
    }

    final public function post_post_initialize()
    {
        //
    }

    final public function inquire_latest_block()
    {
        return (int)requester_single($this->select_node(),
            endpoint: 'api/scan/metadata',
            params: true,
            result_in: 'data',
            timeout: $this->timeout)['blockNum'];
    }

    final public function ensure_block($block_id, $break_on_first = false)
    {
        $this->block_data = requester_single($this->select_node(),
            endpoint: 'api/scan/block',
            params: ['block_num' => $block_id],
            result_in: 'data',
            timeout: $this->timeout);

        $this->block_hash = $this->block_data['hash'];
        $this->block_time = date('Y-m-d H:i:s', (int)$this->block_data['block_timestamp']);
    }

    final public function pre_process_block($block_id)
    {
        if ($block_id === 0)
        {
            $this->set_return_events([]);
            return;
        }

        $extrinsics = $extrinsics_with_hashes = [];

        foreach ($this->block_data['extrinsics'] as $extrinsic)
            if ($extrinsic['extrinsic_hash'])
                $extrinsics_with_hashes[] = $extrinsic['extrinsic_hash'];

        if (!$extrinsics_with_hashes)
        {
            $this->set_return_events([]);
            return;
        }

        $events = [];
        $multi_curl = [];

        foreach ($extrinsics_with_hashes as $extrinsic_hash)
            $multi_curl[] = requester_multi_prepare($this->select_node(),
                endpoint: 'api/scan/extrinsic',
                params: ['hash' => $extrinsic_hash],
                timeout: $this->timeout);

        $curl_results = requester_multi($multi_curl, limit: envm($this->module, 'REQUESTER_THREADS'), timeout: $this->timeout);

        foreach ($curl_results as $v)
        {
            $extrinsics[] = requester_multi_process($v, result_in: 'data');
        }

        $transfers = [];

        foreach ($extrinsics as $i)
        {
            $this_i = $fee = null;

            if (count($i) !== 1)
            {
                $found = false;
                foreach ($i as $j => $k)
                {
                    if ((int)$k['block_num'] === $block_id)
                    {
                        if ($found)
                            throw new ModuleError('Two extrinsics with the same hash in one block');

                        $found = true;
                        $this_i = $j;
                    }
                }
            }
            else
            {
                $this_i = 0;
            }

            if (!isset($this_i))
                continue;  // Block 12831012 contains 0xa36adc0ddedb4b9f9f8de5ef59fa6c99bbb8ca8bbc44d189dd0bedf6bf61b92e
                           // which is present in 12857725, 12856380, 12855736, and many more, but not in 12831012 ¯\_(ツ)_/¯

            if (!$i[$this_i]['transfers'])
                continue;

            $initiating_address = $i[$this_i]['account_id'];

            if ($initiating_address === '') // This is a bit strange
                $initiating_address = $i[$this_i]['transfers'][0]['from'];

            $fee_found = false;
            $extrinsic_failed = $i[$this_i]['failed'];

            foreach ($i[$this_i]['event'] as $event)
            {
                if ($event['event_id'] === 'TransactionFeePaid')
                {
                    if ($fee_found)
                        throw new ModuleError('Two fees');

                    $fee_data = json_decode($event['params'], true);

                    if (count($fee_data) !== 3)
                        throw new ModuleError('Wrong fee data');
                    if ($fee_data[1]['type_name'] !== 'BalanceOf')
                        throw new ModuleError('Wrong fee data');

                    $fee = $fee_data[1]['value'];
                    $fee_found = true;
                    $fee_idx = $event['event_idx'];
                }
            }

            if (!$fee_found) // Uh-oh
            {
                $fee = $i[$this_i]['fee'];
                    $fee_idx = $i[$this_i]['event'][0]['event_idx'];
            }

            foreach ($i[$this_i]['transfers'] as $this_transfer)
            {
                if ((int)$this_transfer['block_num'] !== $block_id)
                    throw new ModuleError('Wrong block');

                if ($extrinsic_failed && !$this_transfer['failed'])
                    throw new ModuleError('Not failed');

                $transfers[] =
                    ['index' => $this_transfer['event_idx'],
                     'hash' => $this_transfer['hash'],
                     'from' => $this_transfer['from'],
                     'to' => $this_transfer['to'],
                     'amount' => $this_transfer['amount'],
                     'failed' => $this_transfer['failed'],
                     'type' => 'transfer'
                    ];
            }

            $transfers[] =
                ['index' => $fee_idx,
                 'hash' => $this_transfer['hash'],
                 'from' => $initiating_address,
                 'to' => 'the-void',
                 'amount' => $fee,
                 'failed' => false,
                 'type' => 'fee'
                ];
        }

        usort($transfers, function($a, $b) {
            return [$a['index']] <=> [$b['index']];
        });

        $sort_key = 0;

        foreach ($transfers as $transfer)
        {
            $events[] = [
                'transaction' => $transfer['hash'],
                'address' => $transfer['from'],
                'sort_key' => $sort_key++,
                'effect' => '-' . $transfer['amount'],
                'failed' => $transfer['failed'],
                'extra' => ($transfer['type'] === 'fee') ? 'f' : null,
            ];

            $events[] = [
                'transaction' => $transfer['hash'],
                'address' => $transfer['to'],
                'sort_key' => $sort_key++,
                'effect' => $transfer['amount'],
                'failed' => $transfer['failed'],
                'extra' => ($transfer['type'] === 'fee') ? 'f' : null,
            ];
        }

        foreach ($events as &$event)
        {
            $event['block'] = $block_id;
            $event['time'] = $this->block_time;
        }

        $this->set_return_events($events);
    }
}
