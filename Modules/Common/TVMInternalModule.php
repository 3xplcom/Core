<?php declare(strict_types=1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes "internal" TVM transactions.
    It requires java-tron node with enabled vm.saveInternalTx option.
*/

abstract class TVMInternalModule extends CoreModule
{
    use TVMTraits;

    public ?BlockHashFormat $block_hash_format = BlockHashFormat::HexWithout0x;
    public ?AddressFormat $address_format = AddressFormat::AlphaNumeric;
    public ?TransactionHashFormat $transaction_hash_format = TransactionHashFormat::HexWithout0x;
    public ?TransactionRenderModel $transaction_render_model = TransactionRenderModel::Even;
    public ?FeeRenderModel $fee_render_model = FeeRenderModel::None;
    public ?PrivacyModel $privacy_model = PrivacyModel::Transparent;

    public ?array $events_table_fields = ['block', 'transaction', 'sort_key', 'time', 'address', 'effect', 'failed'];
    public ?array $events_table_nullable_fields = ['extra'];

    public ?ExtraDataModel $extra_data_model = ExtraDataModel::Default;

    public ?bool $must_complement = true;

    public ?bool $should_return_events = true;
    public ?bool $should_return_currencies = false;
    public ?bool $allow_empty_return_events = true; // Transaction may have no internal transfers

    public ?bool $mempool_implemented = false;
    public ?bool $forking_implemented = true;

    public array $extra_features = [];

    //

    final public function pre_initialize()
    {
        $this->version = 1;
    }

    final public function post_post_initialize()
    {

    }

    final public function pre_process_block($block_id)
    {
        try {
            $r1 = requester_single($this->select_node(),
                endpoint: "/wallet/gettransactioninfobyblocknum?num={$block_id}",
                timeout: $this->timeout); // example block_num 21575018
        } catch (RequesterEmptyArrayInResponseException) {
            $r1 = [];
        }

        $events = [];
        $sort_key = 0;
        foreach ($r1 as $transaction) {
            if (!isset($transaction['internal_transactions']))
                continue;
            foreach ($transaction['internal_transactions'] as $internal_data) {
                foreach ($internal_data['callValueInfo'] as $data) {
                    if (isset($data['callValue']) && !isset($data['tokenId'])) {
                        $events[] = [
                            'transaction' => $transaction['id'],
                            'address' => $this->encode_address_to_base58('0x' . substr($internal_data['caller_address'], 2)),
                            'sort_key' => $sort_key++,
                            'effect' => '-' . $data['callValue'],
                            'failed' => $internal_data['rejected'] ?? false
                        ];

                        $events[] = [
                            'transaction' => $transaction['id'],
                            'address' => $this->encode_address_to_base58('0x' . substr($internal_data['transferTo_address'], 2)),
                            'sort_key' => $sort_key++,
                            'effect' => strval($data['callValue']),
                            'failed' => $internal_data['rejected'] ?? false
                        ];
                    }
                }
            }
        }

        ////////////////
        // Processing //
        ////////////////

        foreach ($events as &$event) {
            $event['block'] = $block_id;
            $event['time'] = $this->block_time;
        }

        $this->set_return_events($events);
    }
}
