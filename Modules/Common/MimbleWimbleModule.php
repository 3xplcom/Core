<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*
 *  This is a parser for MimbleWimble Extension Blocks. Part of the MWEB logic is already processed by
 *  UTXOMainModule (e.g. HogEx transactions)
 *
 *  First Litecoin block: 2265984
 *  RPC examples:
 *  *  ./litecoin-cli getrawtransaction 093134eae2dde51fb9454bf7290056bfab4a71806a1d7472f4178f8e2c0743aa 1 - pegin to the shielded pool
 *  *  ./litecoin-cli getrawtransaction 465897e82992cac62fefbb4d5536bec659e29c723b4373e66502379004121c0a 1 - pegout from the sheilded pool
 *  *  ./litecoin-cli getblockheader 5516801fda679e20dc077b25a5e03ca6f9e4cffbc008d9395d89031ae1e4ed65
 *  *  ./litecoin-cli getblock 5516801fda679e20dc077b25a5e03ca6f9e4cffbc008d9395d89031ae1e4ed65
 *
 *  Docs:
 *  *  https://github.com/litecoin-project/lips/blob/master/lip-0002.mediawiki
 *  *  https://github.com/litecoin-project/lips/blob/master/lip-0003.mediawiki
 *  *  https://github.com/litecoin-project/lips/blob/master/lip-0004.mediawiki
 *  *  https://github.com/litecoin-project/litecoin/releases/tag/v0.21.2
 *  *  https://demo.hedgedoc.org/s/7F9l45zHu
 *  *  https://tara-annison.medium.com/exploring-mimblewimble-now-its-live-d7d2859381e9
 *
 *  Mempool transactions are not covered by this module.
 */

abstract class MimbleWimbleModule extends CoreModule
{
    use UTXOTraits;

    //

    public ?array $events_table_fields = ['block', 'sort_key', 'time', 'address', 'effect'];
    public ?array $events_table_nullable_fields = [];

    public ?bool $should_return_events = true;
    public ?bool $should_return_currencies = false;
    public ?bool $allow_empty_return_events = true;

    public ?bool $mempool_implemented = false;
    public ?bool $forking_implemented = true;

    public ?BlockHashFormat $block_hash_format = BlockHashFormat::HexWithout0x;
    public ?AddressFormat $address_format = AddressFormat::HexWithout0x;
    public ?TransactionHashFormat $transaction_hash_format = TransactionHashFormat::None;
    public ?TransactionRenderModel $transaction_render_model = TransactionRenderModel::None;
    public ?CurrencyFormat $currency_format = CurrencyFormat::Static;
    public ?CurrencyType $currency_type = CurrencyType::FT;
    public ?FeeRenderModel $fee_render_model = FeeRenderModel::None;

    public ?PrivacyModel $privacy_model = PrivacyModel::Shielded; // We don't know transfer amounts in MWEB!

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
        $block = requester_single($this->select_node(), endpoint: "rest/block/{$this->block_hash}.json", timeout: $this->timeout);
        $this->block_time = date('Y-m-d H:i:s', (int)$block['time']);

        if (!isset($block['mweb']))
            throw new ModuleError('No MWEB data in the block');

        $events = [];
        $sort_key = 0;

        foreach ($block['mweb']['inputs'] as $input)
        {
            $events[] = [
                'address' => $input['output_id'],
                'sort_key' => $sort_key++,
                'effect' => '-?', // This is a special value for unknown outbound transfers
            ];
        }

        foreach ($block['mweb']['outputs'] as $output)
        {
            $events[] = [
                'address' => $output['output_id'],
                'sort_key' => $sort_key++,
                'effect' => '+?', // This is a special value for unknown inbound transfers
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
