
class UserConnector {

  constructor(web3, account) {
    this.web3 = web3
    this.address = account.toLowerCase()
    this.cnf = drupalSettings.ethereumUserConnector

    this.authHash = null

    // Dom Elements
    this.message = document.getElementById('myMessage')
    this.button = document.getElementById('ethereum-verify').getElementsByTagName('button')[0]

    this.button.addEventListener('click', (e) => { this.validateEthereumUser(e) })

    // Instantiate contract.
    this.contract = new web3.eth.Contract(
      drupalSettings.ethereum.contracts.register_drupal.jsonInterface,
      drupalSettings.ethereum.contracts.register_drupal.address,
    )
    this.validateContract()

    // Update Hash in background if ther is no verified address yet.
    if (!Drupal.behaviors.ethereumUserConnectorAddressStatus.hasVerifiedAddress()) {
      this.getAuthHash()
      Drupal.behaviors.ethereumUserConnectorAddressStatus.setAddress(this.address)
    }
  }

  /**
   * Set this.contractVerified
   *
   * @returns {Promise<*>}
   */
  async validateContract() {
    const contractVerified = await this.contract.methods.contractExists().call()

    if (contractVerified) {
      // liveLog('Successfully validated contract.')
    }
    else {
      const msg = `Can not verify contract at given address: ${this.cnf.contractAddress}`
      this.message.innerHTML += Drupal.theme('message', msg, 'error')
    }
    this.contractVerified = contractVerified
  }


  /**
   * getAuthHash from Drupal.
   *
   * @returns {Promise<void>}
   */
  async getAuthHash() {
    const url = `${this.cnf.updateAccountUrl + this.address}?_format=json&t=${new Date().getTime()}`
    const response = await fetch(url, { method: 'get', credentials: 'same-origin' })
    if (!response.ok) {
      const msg = 'Can not get a hash for user address. Check permission for \'Access GET on Update Ethereum account resource\''
      this.message.innerHTML += Drupal.theme('message', msg, 'error')
    }
    const authHash = await response.json()
    this.authHash = authHash.hash
  }

  /**
   * validateEthereumUser()
   *
   * @param event
   * @returns {Promise<void>}
   */
  async validateEthereumUser(event) {
    event.preventDefault()
    let userRejected = false

    if (!this.authHash) {
      await this.getAuthHash()
    }

    try {
      await this.contract.methods.newUser(`0x${this.authHash}`).send({ from: this.address })
        .on('transactionHash', (hash) => {
          const msg = `Submitted your verification. TX ID: ${hash}
                      <br /> Transaction is pending Ethereum Network Approval.
                      <br /> It takes some time for Drupal to validate the transaction. Please be patient.`
          this.message.innerHTML += Drupal.theme('message', msg)
          this.button.remove()
          this.transactionHash = hash
        })
        .on('receipt', (receipt) => {
          this.receipt = receipt

          // Here we have the
          // AccountCreatedEvent(address from, bytes32 hash, int256 error)
          // @todo error handling.
          // Hash allready registered to address.
          // accountCreated(msg.sender, drupalUserHash, 4);
          //  --> In this case we can proceed as normal.
          // Hash allready registered to different address.
          // accountCreated(msg.sender, drupalUserHash, 3);
          // Hash too long
          // accountCreated(msg.sender, drupalUserHash, 2);
          // Registry is disabled because a newer version is available
          // accountCreated(msg.sender, drupalUserHash, 1);
          // Success
          // accountCreated(msg.sender, drupalUserHash, 0);
          // @todo The event would ideally be watched at server side.

          this.verifySubmission()
        })
        // Triggers for every confirm
        // .on('confirmation', (confirmationNumber, receipt) => {
        //   window.console.log('on confirmation', [confirmationNumber, receipt])
        // })
        .on('error', window.console.error)
    }
    catch (err) {
      userRejected = err.message.includes('denied transaction')
      if (userRejected) {
        this.message.innerHTML += Drupal.theme('message', 'You rejected the Transaction', 'error')
      }
      else {
        const msg = `<pre> ${err.message}</pre>`
        this.message.innerHTML += Drupal.theme('message', msg, 'error')
      }
    }
  }

  /**
   * Trigger backend to validate user submission.
   *
   * There is a AJAX handler which will assign an new role to the user if submission
   * was verified by Drupal.
   */
  async verifySubmission() {

    // Let Drupal backend verify the transaction submitted by user.
    const url = `${this.cnf.verificationUrl + this.authHash}?_format=json&t=${new Date().getTime()}`
    const response = await fetch(url, { method: 'get', credentials: 'same-origin' })
    if (!response.ok) {
      const msg = 'Can not get verificationUrl'
      this.message.innerHTML += Drupal.theme('message', msg, 'error')
    }
    const result = await response.json()

    const resultType = result.success ? 'status' : 'error'
    this.message.innerHTML = Drupal.theme('message', result.message, resultType)
    window.location.reload(true)
  }

}

/**
 * Message template
 *
 * @param content String - Message string (may contain html)
 * @param type String [status|warning|error] - Message type.
 *   Defaults to status - a green info message.
 *
 * @return String HTML.
 */
Drupal.theme.message = (content, type = 'status') => {
  let msg = `<div role="contentinfo" class="messages messages--${type}"><div role="alert">`
  msg += `<h2 class="visually-hidden">${type.charAt(0).toUpperCase()}${type.slice(1)} message</h2>`
  msg += `${content}</div></div>`
  return msg
}

/**
 * web3Ready is fired by ethereum_txsigner.txsigner.mascara
 */
window.addEventListener('web3Ready', () => {
  window.web3Runner.runWhenReady({
    requireAccount: true,
    networkId: drupalSettings.ethereum.network.id,
    run: (web3, account = null) => {
      Drupal.behaviors.ethereum_user_connector = new UserConnector(web3, account)
    },
  })
})
