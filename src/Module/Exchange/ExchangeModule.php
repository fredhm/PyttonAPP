<?php
namespace Erpk\Harvester\Module\Exchange;

use Erpk\Harvester\Module\Module;
use Erpk\Harvester\Exception\ScrapeException;
use Erpk\Harvester\Exception\InvalidArgumentException;
use Erpk\Harvester\Filter;
use Erpk\Harvester\Client\Selector\Paginator;
use XPathSelector\Selector;

class ExchangeModule extends Module
{
    const CURRENCY = 0;
    const GOLD     = 1;
    
    public function scan($mode, $page = 1)
    {
        switch ($mode) {
            case self::CURRENCY:
                $currencyId = 1;
                break;
            case self::GOLD:
                $currencyId = 62;
                break;
            default:
                throw new InvalidArgumentException('Invalid currency');
        }

        $page = Filter::page($page);
        $this->getClient()->checkLogin();
        $request = $this->getClient()->post('economy/exchange/retrieve/');
        $request->addPostFields([
            '_token'         => $this->getSession()->getToken(),
            'currencyId'     => $currencyId,
            'page'           => $page-1,
            'personalOffers' => 0,
        ]);
        
        $response = $request->send();
        return $this->parseOffers($response->json());
    }
    
    public static function parseOffers($data)
    {
        $xs = Selector::loadHTML($data['buy_mode']);

        $result = new OfferCollection();
        $result->setPaginator(new Paginator($xs));
        $result->setGoldAmount((float)$data['gold']['value']);
        $result->setCurrencyAmount((float)$data['ecash']['value']);
        
        $rows = $xs->findAll('//*[@class="exchange_offers"]/tr');
        foreach ($rows as $row) {
            $url = $row->find('td[1]/a/@href')->extract();
            $offer = new Offer();
            $offer->id         = (int)substr($row->find('td[3]/strong[2]/@id')->extract(), 14);
            $offer->amount     = (float)str_replace(',', '', $row->find('td[2]/strong/span')->extract());
            $offer->rate       = (float)$row->find('td[3]/strong[2]/span')->extract();
            $offer->sellerId   = (int)substr($url, strripos($url, '/') + 1);
            $offer->sellerName = (string)$row->find('td[1]/a/@title')->extract();
            $result[] = $offer;
        }
        
        return $result;
    }
    
    public function buy($id, $amount)
    {
        if ($id instanceof Offer) {
            $id = $id->id;
        }
        $id = Filter::id($id);
        $amount = filter_var($amount, FILTER_VALIDATE_FLOAT);
        if (!$amount) {
            throw new InvalidArgumentException('Specified amount is not a valid number.');
        }
        
        $this->getClient()->checkLogin();
        $request = $this->getClient()->post('economy/exchange/purchase/');
        $request->addPostFields([
            'offerId' => $id,
            'amount'  => $amount,
            '_token'  => $this->getSession()->getToken(),
            'page'    => 0
        ]);
        $request->setHeader('Referer', $this->getClient()->getBaseUrl().'/economy/exchange-market/');
        return $request->send()->json();
    }
}