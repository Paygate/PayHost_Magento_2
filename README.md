# PayHost_Magento_2

## Paygate PayHost (with tokenization) plugin v1.1.0 for Magento v2.4.7

This is Paygate Payhost (with tokenization) for Magento 2. Please feel free to contact the Payfast support team at
support@payfast.help should you require any assistance.

## Installation

1. **Download the Plugin**

    - Visit the [releases page](https://github.com/Paygate/PayHost_Magento_2/releases) and
      download [PayGate.zip](https://github.com/Paygate/PayHost_Magento_2/releases/download/v1.1.0/PayGate.zip).

2. **Install the Plugin**

    - Extract the contents of `PayGate.zip`, then upload the newly created **PayGate** directory into your Magento
      app/code directory (e.g. magentorootfolder/app/code/).
    - Run the following Magento CLI commands:
        ```console
        php bin/magento module:enable PayGate_PayHost
        php bin/magento setup:upgrade
        php bin/magento setup:di:compile
        php bin/magento setup:static-content:deploy
        php bin/magento indexer:reindex
        php bin/magento cache:clean
        ```
3. **Configure the Plugin**

    - Login to the Magento admin panel.
    - Navigate to **Stores > Configuration > Sales > Payment Methods** and click on
      **Paygate Payhost**.
    - Configure the module according to your needs, then click the **Save Config** button.

## Collaboration

Please submit pull requests with any tweaks, features or fixes you would like to share.
