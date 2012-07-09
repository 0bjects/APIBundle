Installation instructions:

1.add this lines to your deps file:

[FacebookApiLibrary]
    git=http://github.com/facebook/php-sdk.git

[APIBundle]
    git=http://github.com/0bjects/APIBundle.git
    target=../src/Objects/APIBundle

2.run bin/vendors update

3.add this line to your app/AppKernel.php :

new Objects\APIBundle\ObjectsAPIBundle(),

4.add this line to the file app/autoload.php

'OAuth'            => __DIR__.'/../src/Objects/APIBundle/libraries/abraham',


5.add the routes in your app/config/routing.yml:

ObjectsAPIBundle:
    resource: "@ObjectsAPIBundle/Resources/config/routing.yml"
    prefix:   /

6.enable the translation in your config.yml file :

framework:
    esi:             ~
    translator:      { fallback: %locale% }

optional:

configure the parameters in Resources/config/config.yml file

IMPORTANT NOTE:
***********************
remove the .git folder in src/Objects/APIBundle if you are going to make project specific changes
so that you do not push them to the bundle repo and remove the deps and deps.lock lines
***********************