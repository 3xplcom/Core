<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023-2024 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the main Optimism module. It requires a geth node to run.  */

final class OptimismMainModule extends EVMMainModule implements Module, BalanceSpecial, TransactionSpecials, AddressSpecials
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'optimism';
        $this->module = 'optimism-main';
        $this->is_main = true;
        $this->first_block_date = '2021-11-11';
        $this->first_block_id = 0;
        $this->currency = 'ethereum';
        $this->currency_details = ['name' => 'Ethereum', 'symbol' => 'ETH', 'decimals' => 18, 'description' => null];
        $this->mempool_implemented = false; // Unlike other EVMMainModule heirs, Optimism doesn't implement mempool

        // EVMMainModule
        $this->evm_implementation = EVMImplementation::geth;
        $this->extra_features = [EVMSpecialFeatures::HasSystemTransactions, EVMSpecialFeatures::OPStack];
        $this->reward_function = function($block_id)
        {
            return '0';
        };
        $this->l1_fee_vault = '0x420000000000000000000000000000000000001A';  // https://github.com/ethereum-optimism/op-geth/blob/c6ea6fa09d4e7df6d1ca6b2d32bcb139f021b1e2/params/protocol_params.go#L29
        $this->base_fee_recipient = '0x4200000000000000000000000000000000000019'; 

        // Handles
        $this->handles_implemented = true;
        // This is the full list of names supported by 3DNS, including .box
        $this->handles_regex = '/(.*)\.(box|ac|academy|accountant|accountants|actor|agency|ai|airforce|apartments|app|archi|army|art|asia|associates|at|attorney|auction|audio|auto|autos|baby|band|bar|bargains|bayern|beauty|beer|best|bible|bid|bike|bingo|bio|biz|black|blackfriday|blog|blue|boats|bond|boston|boutique|builders|business|buzz|ca|cab|cafe|cam|camera|camp|capital|car|cards|care|careers|cars|casa|cash|casino|catering|cc|center|cfd|charity|chat|cheap|christmas|church|city|claims|cleaning|click|clinic|clothing|cloud|club|co|coach|codes|coffee|college|com|community|company|computer|condos|construction|consulting|contact|contractors|cooking|cool|coupons|courses|credit|creditcard|cricket|cruises|cyou|dance|date|dating|de|dealer|deals|degree|delivery|democrat|dental|dentist|design|dev|diamonds|diet|digital|direct|directory|discount|doctor|dog|domains|download|earth|education|email|energy|engineer|engineering|enterprises|equipment|estate|eu|events|exchange|expert|exposed|express|fail|faith|family|fans|farm|fashion|feedback|film|finance|financial|fish|fishing|fit|fitness|flights|florist|flowers|football|forsale|foundation|fun|fund|furniture|futbol|fyi|gallery|game|games|garden|gay|gift|gifts|gives|glass|global|gmbh|gold|golf|graphics|gratis|green|gripe|group|guide|guitars|guru|hair|haus|health|healthcare|help|hiphop|hiv|hockey|holdings|holiday|homes|horse|hospital|host|hosting|house|icu|immo|immobilien|in|inc|industries|info|ink|institute|insure|international|investments|io|irish|ist|istanbul|jetzt|jewelry|juegos|kaufen|kids|kim|kitchen|la|land|lat|law|lawyer|lease|legal|lgbt|life|lighting|limited|limo|link|live|llc|loan|loans|lol|london|love|ltd|luxe|maison|makeup|management|market|marketing|mba|me|media|melbourne|memorial|men|menu|miami|mobi|moda|moe|mom|money|monster|mortgage|motorcycles|movie|mx|navy|net|network|news|ninja|nl|nyc|observer|one|online|ooo|org|organic|page|partners|parts|party|pet|ph|photo|photography|photos|pics|pictures|pink|pizza|place|plumbing|plus|poker|press|pro|productions|promo|properties|property|protection|pub|pw|quest|racing|realty|recipes|red|rehab|reise|reisen|rent|rentals|repair|report|republican|rest|restaurant|review|reviews|rip|rocks|rodeo|run|sale|salon|sarl|sbs|school|schule|science|security|services|sexy|sh|shiksha|shoes|shop|shopping|show|singles|site|ski|skin|soccer|social|software|solar|solutions|space|storage|store|stream|studio|study|style|sucks|supplies|supply|support|surf|surgery|sydney|systems|tattoo|tax|taxi|team|tech|technology|tel|tennis|theater|theatre|tickets|tienda|tips|tires|today|tools|top|tours|town|toys|trade|training|travel|tube|tv|uk|university|uno|us|vacations|vegas|ventures|vet|viajes|video|villas|vin|vip|vision|vodka|vote|voyage|watch|webcam|website|wedding|whoswho|wiki|win|wine|work|works|world|ws|wtf|xyz|yachts|yoga|zone)/';
        $this->api_get_handle = function($handle)
        {
            if (!preg_match($this->handles_regex, $handle))
                return null;

            require_once __DIR__ . '/../Engine/Crypto/Keccak.php';

            $hash = $this->ens_name_to_hash($handle);

            if (is_null($hash) || $hash === '')
                return null;

            // First, we look for forward resolution
            $address = $this->ens_get_data_from_resolver('0xf97aac6c8dbaebcb54ff166d79706e3af7a813c8', $hash, '0x3b3b57de', -40);

            if ($address === '0000000000000000000000000000000000000000') // Then, if nothing has been found, we look for the owner (ownerOf)
                $address = $this->ens_get_data_from_resolver('0xbb7b805b257d7c76ca9435b3ffe780355e4c4b17', $hash, '0x6352211e', -40);

            if ($address && $address !== '0000000000000000000000000000000000000000')
                return '0x' . $address;
            else
                return null;
        };
    }
}
