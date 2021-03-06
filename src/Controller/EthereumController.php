<?php

namespace Drupal\ethereum\Controller;

use Drupal\Core\Controller\ControllerBase;

use Ethereum\Ethereum;
use Drupal\ethereum\EthereumServerInterface;
use Ethereum\DataType\EthBlockParam;
use Ethereum\DataType\EthB;
use Ethereum\DataType\EthS;
use Drupal\Core\Render\Markup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller routines for Ethereum routes.
 */
class EthereumController extends ControllerBase {

  /**
   * The Ethereum JsonRPC client.
   *
   * @var \Ethereum\Ethereum
   */
  protected $web3;

  // @todo Doesn't seem to be propagetad to the library anymore.
  private $debug = TRUE;

  /**
   * Constructs a new EthereumController.
   *
   * @param string|NULL $host
   *    Ethereum node url.
   *
   * @throws \Exception
   */
  public function __construct(string $host = null) {
    if (!$host) {
      $host = $this->getDefaultServer()->get('url');
    }
    $this->web3 = new Ethereum($host);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(self::getDefaultServer()->getUrl());
  }

  /**
   * Returns Ethereum Networks.
   *
   * @return array
   *   Array of Ethereum Networks containing ID, Name, Description.
   */
  public static function getNetworksAsOptions() {
    $networks = [];
      foreach (self::getNetworks() as $k => $item) {
        $networks[$item['id']] = $item['label'] . ' (' . $item['id'] . ')  - ' . $item['description'];
      }
      return $networks;
  }

  /**
   * Returns Ethereum Networks.
   *
   * @return array
   *   Array of Ethereum Networks containing ID, Name, Description.
   */
  public static function getNetworks() {
    $networks = \Drupal::config('ethereum.ethereum_networks')->getRawData();
    // Filter out non-numeric keys, like _core
    $networks = array_filter($networks, function ($k){ return is_int($k);}, ARRAY_FILTER_USE_KEY);
    $keyEd = [];
    foreach ($networks as $k => $net) {
      $keyEd[$net['id']] = $net;
    }
    return $keyEd;
  }

  /**
   * Ethereum Servers as options.
   *
   * @param bool $filter_enabled
   *   (optional) Restrict to enabled servers. Defaults to FALSE.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   *
   * @return array
   *   Array [] = machine_name => Label
   */
  public static function getServerOptionsArray($filter_enabled = FALSE){
    $servers = self::getServers($filter_enabled);
    return array_map(function($k) { return $k->label(); }, $servers);
  }

  /**
   * Returns Ethereum Servers.
   *
   * @param bool $filter_enabled
   *    (optional) Restrict to enabled servers. Defaults to FALSE.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   *
   * @return \Drupal\ethereum\EthereumServerInterface[]
   */
  public static function getServers($filter_enabled = FALSE) {
    $storage = \Drupal::entityTypeManager()->getStorage('ethereum_server');
    if ($filter_enabled) {
      $servers = $storage->loadByProperties(['status' => TRUE]);
    }
    else {
      $servers = $storage->loadMultiple();
    }
    return $servers;
  }

  /**
   * Returns Ethereum Default Server
   *
   * @return EthereumServerInterface
   *   Ethereum current Default server config entity.
   *
   * @throws \Exception
   */
  public static function getDefaultServer() {
    $current = \Drupal::config('ethereum.settings')->get('current_server');
    $server = \Drupal::entityTypeManager()->getStorage('ethereum_server')->load($current);
    if (!$server) {
      throw new \Exception('Current default server (' . $current . ') does not exist.');
    }
    if (!$server->status()) {
      throw new \Exception('Current default server is not enabled.');
    }
    return $server;
  }

  /**
   * Outputs function call logging as drupal message.
   *
   * This will output logs of all calls if $this->debug = TRUE.
   * Debug log will be emptied after call.
   *
   * @param bool $clear
   *   If empty is set we will empty the debug log.
   *   You may use to debug a single call.
   */
  public function debug($clear = FALSE) {
    $html = $this->web3->debugHtml;
    $this->web3->debugHtml = '';
    if (!$clear && $this->debug) {
      // Remove last HR Tag.
      $html = strrev(implode('', explode(strrev('<hr />'), strrev($html), 2)));
      $this->messenger()->addMessage(Markup::create($html), 'warning');
    }
  }

  /**
   * Displays the ethereum status report page.
   *
   * This page provides a overview about Ethereum functions and usage.
   *
   * @throws \Exception
   *
   * @return array
   *   Render array. Table with current status of the ethereum node.
   */
  public function status() {

    // Default server.
    $server = $this->getDefaultServer();

    // Validate active server.
    $liveStatus = $server->validateConnection();
    $this->messenger()->addMessage(
      $liveStatus['message'], ($liveStatus['error']) ? 'error' : 'status'
    );

    // Config info.
    $serverInfo = [
      '#type' => 'fieldset',
      '#title' => $this->t('Ethereum connection'),
      'current_server' => $this->getServerInfoAsTable($server),
    ];

    // Get Live status.
    $status_rows[] = [$this->t("Client version (web3_clientVersion)"), $this->web3->web3_clientVersion()->val()];
    $status_rows[] = [$this->t("Listening (net_listening)"), $this->web3->net_listening()->val() ? '✔' : '✘'];
    $status_rows[] = [$this->t("Peers (net_peerCount)"), $this->web3->net_peerCount()->val()];

    // @todo This creates a RLP error :?
    //    $status_rows[] = [$this->t("Protocol version (eth_protocolVersion)"), $this->web3->eth_protocolVersion()->val()];

    $status_rows[] = [$this->t("Network version (net_version)"), $this->web3->net_version()->val()];
    $status_rows[] = [$this->t("Syncing (eth_syncing)"), $this->web3->eth_syncing()->val() ? '✔' : '✘'];

    // Mining and Hashrate.
    $status_rows[] = [$this->t("Mining (eth_mining)"), $this->web3->eth_mining()->val() ? '✔' : '✘'];

    $hash_rate = $this->web3->eth_hashrate();
    $mining = is_a($hash_rate, 'EthQ') ? ((int) ($hash_rate->val() / 1000) . ' KH/s') : '✘';
    $status_rows[] = [$this->t("Mining hashrate (eth_hashrate)"), $mining];

    // Gas price is returned in WEI. See: http://ether.fund/tool/converter.
    $price = $this->web3->eth_gasPrice()->val();
    $price = $price . 'wei ( ≡ ' . number_format(($price / 1000000000000000000), 8, '.', '') . ' Ether)';
    $status_rows[] = [$this->t("Current price per gas in wei (eth_gasPrice)"), $price];

    // Accounts.
    $status_rows[] = [$this->t("<b>Accounts info</b>"), ''];
    $coin_base = $this->web3->eth_coinbase()->hexVal();
    if ($coin_base === '0x0000000000000000000000000000000000000000') {
      $coin_base = 'No coinbase available at this network node.';
    }
    $status_rows[] = [$this->t("Coinbase (eth_coinbase)"), $coin_base];
    $address = array();
    foreach ($this->web3->eth_accounts() as $addr) {
      $address[] = $addr->hexVal();
    }
    $status_rows[] = [$this->t("Accounts (eth_accounts)"), implode(', ', $address)];

    $serverLiveInfo = [
      '#type' => 'fieldset',
      '#title' => $this->t('Ethereum node live status.'),
      'server_status' => [
        'table' => [
          '#theme' => 'table',
          '#rows' => $status_rows,
        ]
      ]
    ];


    $random_rows[] = [$this->t('<b>JsonRPC standard Methods</b>'), $this->t('Read more about <a href="https://github.com/ethereum/wiki/wiki/JSON-RPC">Ethereum JsonRPC-API</a> implementation.')];
    $random_rows[] = [$this->t('<b>Ethereum-PHP</b>'), $this->t('Ethereum <a href="http://ethereum-php.org/">Web3 PHP API reference</a> and <a href="https://github.com/digitaldonkey/ethereum-php">codebase</a>.')];


    // Blocks.
    $random_rows[] = [$this->t("<b>Block info</b>"), ''];
    $block_latest = $this->web3->eth_getBlockByNumber(new EthBlockParam('latest'), new EthB(FALSE));
    $random_rows[] = [
      $this->t("Latest block age"),
      \Drupal::service('date.formatter')->format($block_latest->getProperty('timestamp'), 'html_datetime'),
    ];

    // Testing_only.

    $block_earliest = $this->web3->eth_getBlockByNumber(new EthBlockParam('earliest'), new EthB(FALSE));
    $random_rows[] = [
      $this->t("Age of 'earliest' block<br/><small>The 'earliest' block has no timestamp on many networks.</small>"),
      \Drupal::service('date.formatter')->format($block_earliest->getProperty('timestamp'), 'html_datetime'),
    ];
    $random_rows[] = [
      $this->t("Client first (eth_getBlockByNumber('earliest'))"),
      Markup::create('<div style="max-width: 800px; max-height: 120px; overflow: scroll">' . $this->web3->debug('', $block_earliest) . '</div>'),
    ];

    // Second param will return TX hashes instead of full TX.
    $block_latest = $this->web3->eth_getBlockByNumber(new EthBlockParam('latest'), new EthB(FALSE));
    $random_rows[] = [
      $this->t("Client first (eth_getBlockByNumber('latest'))"),
      Markup::create('<div style="max-width: 800px; max-height: 120px; overflow: scroll">' . $this->web3->debug('', $block_latest) . '</div>'),
    ];
    $random_rows[] = [
      $this->t("Uncles of latest block"),
      Markup::create('<div style="max-width: 800px; max-height: 120px; overflow: scroll">' . $this->web3->debug('', $block_latest->getProperty('uncles')) . '</div>'),
    ];
    $high_block = $this->web3->eth_getBlockByNumber(new EthBlockParam(999999999), new EthB(FALSE));
    $random_rows[] = [
      $this->t("Get hash of a high block number<br /><small>Might be empty</small>"),
      $high_block->getProperty('hash'),
    ];


    // More.

    // @todo This might be wrong.Check if web3_sha3 expects a UTF8 string.
    //
    $random_rows[] = [
      $this->t("web3_sha3('Hello World')"),
      $this->web3->web3_sha3(new EthS('Hello World'))->hexVal(),
    ];

    // NON standard JsonRPC-API Methods below.
    $random_rows[] = [$this->t('<b>Non standard methods</b>'), $this->t('PHP Ethereum controller API provides additional methods. They are part of the <a href="https://github.com/digitaldonkey/ethereum-php">Ethereum PHP library</a>, but not part of JsonRPC-API standard.')];
    $random_rows[] = [$this->t("getMethodSignature('validateUserByHash(bytes32)')"), $this->web3->getMethodSignature('validateUserByHash(bytes32)')];

    $serverRandomRows = [
      '#type' => 'fieldset',
      '#title' => $this->t('Random stuff.'),
      'server_status' => [
        'table' => [
          '#theme' => 'table',
          '#rows' => $random_rows,
        ]
      ]
    ];

    // Debug output for all calls since last call of
    // $this->debug() or $this->debug(TRUE).
    // $this->debug();

    return [
      'server_info' => $serverInfo,
      'server_live_status' => $serverLiveInfo,
      'random_stuff' => $serverRandomRows,
    ];
  }

  /**
   * Server info as render Array Table.
   *
   * @param $server EthereumServerInterface
   *    Server config entity.
   *
   * @return array
   *    Table render array.
   */
  public function getServerInfoAsTable(EthereumServerInterface $server) {

    $networks = EthereumController::getNetworks();
    $currentNet = $networks[$server->get('network_id')];

    $formElement = array(
      '#type' => 'table',
    );
    $formElement['info'] = [
      'label' => array('#markup' => 'Node info'),
      'content' => [
        '#markup' => $server->label() . '<br />' . '<small>' . $server->get('description'). '</small>',
      ],
    ];
    $formElement['config_id'] = [
      'label' => array('#markup' => 'Config name'),
      'content' => [
        '#markup' =>  $server->id(),
      ],
    ];
    $formElement['url'] = [
      'label' => array('#markup' => 'RPC Url'),
      'content' => ['#markup' => $server->get('url')],
    ];
    $formElement['network'] = [
      'label' => array('#markup' => 'Network info'),
      'content' => [
        '#markup' =>
          '<strong>' .  $currentNet['label'] . ' (Ethereum Network Id: ' .  $currentNet['id'] . ')</strong><br />'
          . $currentNet['description'] . '<br />'
      ],
    ];
    $formElement['explorer'] = [
      'label' => array('#markup' => 'Blockchain Explorer'),
      'content' => [
        '#markup' => $currentNet['link_to_address']
      ]
    ];
    return $formElement;
  }
}
