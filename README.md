# ![picture logo](https://github.com/syncgw/gui-bundle/blob/master/assets/syncgw.png "sync•gw") #
 
![](https://img.shields.io/packagist/v/syncgw/roundcube-syncgw.svg)
![](https://img.shields.io/packagist/l/syncgw/roundcube-syncgw.svg)
![](https://img.shields.io/packagist/dt/syncgw/roundcube-syncgw.svg)
 
**sync•gw** is the one and only fully portable server software available providing synchronization service between nearly any mobile device and your web server.

## roundcube-syncgw bundle ##

With this Plugin you can specify in your [RoundCube](https://roundcube.net) installation which address books, calendars, task lists and notes you want to synchronize with your cell phone / smart phone. For address boks you can specify whether you want to synchronize only contacts with a phone number specified or if you want to synchronize all contacts within this address book.

**Requirements**

To use this plugin, you need

* A functional [RoundCube](https://roundcube.net) installation.
* [sync•gw](https://github.com/syncgw) installed and configured in RoundCube root directory.

**Installation**
* Please install [sync•gw plugin](https://github.com/syncgw/roundcube-syncgw).

   ```
  composer require syncgw/roundcube-syncgw
   ```

* If you want to synchronize address books, then you don't need any additional RoundCube plugin.

* If you want to use shared address books, then you need to install [globaladdressbook-Plugin](https://github.com/johndoh/roundcube-globaladdressbook).

   ```
   composer require johndoh/globaladdressbook
   ```
  
* If you want to synchronize calendar, then you need to install [calender plugin](https://packagist.org/packages/kolab/calendar).

   ```
  composer require kolab/calendar
   ```

* If you want to synchronize tasklis, then you need to install [tasklist plugin](https://plugins.roundcube.net/packages/kolab/tasklist).

   ```
  composer require kolab/tasklist
   ```
  
    **Caution:** If you use the plugin and receive a error message in RoundCube log file, then please check file `plugins/tasklist/config.inc.php`. There `$config['tasklist_driver'] = 'database';` should be specified.
  
* If you want to synchronize notes, then you need to install [ddnotes plugin](https://packagist.org/packages//dondominio/ddnotes).

   ```
  composer require dondominio/ddnotes 
   ```

* Activate our plugin by adding plugin name in file `config/config.inc.php`

   ```
  $config['plugins'] = array(
	...
	'roundcube-syncgw',
	[the other optional plugins]
	...
  );
   ```
	
**Usage**

* Go to menu `Settings` and configure synchronization settings by selecting `Synchronization settings`.If this selection does not appear, then you did not install **sync•gw** in RoundCube root directory.
* Now you're ready to synchronize your selected data with your cell phone / smart phone. If you need some help how to configure you device, take a look a [sync•gw FAQ](https://github.com/syncgw/doc-bundle/blob/master/FAQ.md).

Please enjoy!

If you enjoy my software, I would be happy to receive a donation.

<a href="https://www.paypal.com/donate/?hosted_button_id=DS6VK49NAFHEQ" target="_blank" rel="noopener">
  <img src="https://www.paypalobjects.com/en_US/DK/i/btn/btn_donateCC_LG.gif" alt="Donate with PayPal"/>
</a>


[[Documentation](https://github.com/syncgw/doc-bundle/blob/master/README.md)]
[[System requirements](https://github.com/syncgw/doc-bundle/blob/master/PreReqs.md)] 
[[Available bundles](https://github.com/syncgw/doc-bundle/blob/master/Packages.md)] 
[[List of all changes](https://github.com/syncgw/doc-bundle/blob/master/Changes.md)] 
[[Additional Downloads](https://github.com/syncgw/doc-bundle/blob/master/Downloads.md)] 
[[Frequently asked questions](https://github.com/syncgw/doc-bundle/blob/master/FAQ.md)] 
[[Supported feature](https://github.com/syncgw/doc-bundle/blob/master/Features.md)]
