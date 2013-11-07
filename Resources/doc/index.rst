Installation instructions:

1.add this lines to your composer.json file in "require" section:
"ruudk/twitter-oauth": "dev-master",
"facebook/php-sdk": "dev-master",
"google/api-client": "dev-master"

2.add this line to your app/AppKernel.php :

new Objects\APIBundle\ObjectsAPIBundle(),

3.add the routes in your app/config/routing.yml:

ObjectsAPIBundle:
    resource: "@ObjectsAPIBundle/Resources/config/routing.yml"
    prefix:   /

4.enable the translation in your config.yml file :

framework:
    esi:             ~
    translator:      { fallback: %locale% }

5.run composer update

optional:

configure the parameters in Resources/config/config.yml file
