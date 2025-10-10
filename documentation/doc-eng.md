# PaynetEasy Payment Plugin for Prestashop 9

# 1. [Requirements](https://github.com/annihilatoratm/prestashop9-doc/blob/main/documentation/doc-eng.md#1-requirements-1)
# 2. [Functionality](https://github.com/annihilatoratm/prestashop9-doc/blob/main/documentation/doc-eng.md#2-functionality-1)
# 3. [Installation Steps](https://github.com/annihilatoratm/prestashop9-doc/blob/main/documentation/doc-eng.md#3-installation-steps-1)
# 4. [Package Build](https://github.com/annihilatoratm/prestashop9-doc/blob/main/documentation/doc-eng.md#4-package-build-1)
# 5. [Plugin Installation](https://github.com/annihilatoratm/prestashop9-doc/blob/main/documentation/doc-eng.md#5-plugin-installation-1)
# 6. [Plugin Configuration](https://github.com/annihilatoratm/prestashop9-doc/blob/main/documentation/doc-eng.md#6-plugin-configuration-1)
# 7. [Plugin Uninstallation](https://github.com/annihilatoratm/prestashop9-doc/blob/main/documentation/doc-eng.md#7-plugin-uninstallation-1)
# 8. [Payment Flow](https://github.com/annihilatoratm/prestashop9-doc/blob/main/documentation/doc-eng.md#8-payment-flow)

## 1. Requirement
## 2. Functionality
## 3. Installation Steps
## 4. Package Build
## 5. Plugin Installation

5.1. [Download package containing module](00-introduction.md#get_package).  
5.2. Extract the contents into the Prestashop root directory.  
5.3. Open the Prestashop admin panel. 
5.4. Navigate to _Modules_ > _Module Manager_ (1). 
5.5. Install Module.  
    5.5.1. Search for "Payneteasy" using the search field (2).
    5.5.2. Click **Install** to install the plugin.  
5.6. Confirm installation, even though the module is not verified by Prestashop.  

<img src="/images/prestashop-7.png" width=60% height=60%>

## 6. Plugin Configuration.  

6.1. Open Prestashop Administration Panel.    
6.2. Go to the _Modules_ > _Module Manager_ section. (1).    
6.3. Open the module settings page.  
    6.3.1. Search for "Payneteasy" using the search field (2).  
    6.3.2. Open module setting page by pressing **Configure** button (3).  
       
  <img src="/images/prestashop-1-1.png" width=60% height=60%>
  
6.4. Fill in the required configuration settings.  
<img src="/images/prestashop-1-2.png" width=60% height=60%>

## 7. Plugin Uninstallation.

7.1. Open Prestashop Administration Panel.  
7.2. Navigate to _Modules_ > _Module Manager_.  
7.3. Remove Module.  
    7.3.1. Search for "payneteasy" using the search field.  
    7.3.2. Open the list of actions for the module.  
    7.3.3. Choose **Uninstall**.  
       
<img src="/images/prestashop-1-3.png" width=60% height=60%>

## 8. Payment Flow

8.1. On the main page, select a product and click **Add to cart**.  
<img src="/images/prestashop-1.png" width=60% height=60%>
<img src="/images/prestashop-2.png" width=60% height=60%>
  
8.2. A confirmation pop-up will appear. Click **Proceed to checkout** to continue or **Continue shopping** to go back.  

<img src="/images/prestashop-popup.png" width=60% height=60%>

8.3. When ready, click the **Cart** icon in the top navigation bar to review the items.   

<img src="/images/prestashop-3.png" width=60% height=60%>

8.4. Click **Proceed to checkout** to begin the payment process:  
   8.4.1. Personal Information.    
   8.4.2. Addresses.   
   8.4.3. Shipping Method.   
   8.4.4. Payment Method (Select Paynet Payment Method).   

<img src="/images/prestashop-5.png" width=60% height=60%>
<img src="/images/prestashop-6.png" width=60% height=60%>
