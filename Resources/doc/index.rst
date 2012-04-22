add this lines to your deps file:

[FacebookApiLibrary]
    git=http://github.com/facebook/php-sdk.git

[APIBundle]
    git=repo@184.107.198.186:/home/repos/APIBundle.git
    target=../src/Objects/APIBundle

*******************************************************************
run bin/vendors update you will be asked about the password 2 times
*******************************************************************

add this line to your app/AppKernel.php :

new Objects\APIBundle\ObjectsAPIBundle(),

add this line to the file app/autoload.php

'OAuth'            => __DIR__.'/../src/Objects/APIBundle/libraries/abraham',


add the routes in your app/config/routing.yml:

ObjectsAPIBundle:
    resource: "@ObjectsAPIBundle/Resources/config/routing.yml"
    prefix:   /

enable the translation
