<?php

namespace Objects\APIBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Response;

/**
 * @author mahmoud
 */
class GoogleController extends Controller {

    /**
     * The singleton instance
     *
     * @var \Google_Client
     */
    private static $client = null;

    /**
     * @return \Google_Client
     */
    private function getGoogleClient() {
        if (null === self::$client) {
            $container = $this->container;
            $client = new \Google_Client();
            $client->setClientId($container->getParameter('google_client_id'));
            $client->setClientSecret($container->getParameter('google_client_secret'));
            $client->setApprovalPrompt('auto');
            self::$client = $client;
        }
        return self::$client;
    }

    /**
     * @return \Google_Oauth2Service
     */
    private function getOauthService() {
        $client = $this->getGoogleClient();
        $client->setRedirectUri($this->generateUrl('google_oauth_callback', array(), true));
        return new \Google_Oauth2Service($client);
    }

    public function oauthAction($redirectRoute, $popup = 'no') {
        $session = $this->get('session');
        $session->set('redirectRoute', $redirectRoute);
        if ($popup == 'yes') {
            $session->set('googlePopup', true);
        }
        $client = $this->getGoogleClient();
        // add oauth service to google client to generate correct url
        $this->getOauthService();
        return $this->redirect($client->createAuthUrl());
    }

    public function oauthCallbackAction() {
        $client = $this->getGoogleClient();
        $oauth = $this->getOauthService();
        $session = $this->get('session');
        $redirectRoute = $session->remove('redirectRoute');
        try {
            $auth_token = $client->authenticate();
            $client->setAccessToken($auth_token);
            $user = $oauth->userinfo->get();
            if (isset($user['id']) && isset($user['email'])) {
                $session->set('googleUserInfo', $user);
                if ($session->has('googlePopup')) {
                    $session->remove('googlePopup');
                    return new Response('
                <script>
                    window.opener.top.location.href = "' . $this->generateUrl($redirectRoute) . '";
                    self.close();
                </script>
                ');
                }
                return $this->forward($controller);
                return $this->redirect($this->generateUrl($redirectRoute));
            }
        } catch (\Exception $e) {

        }
        $session->getFlashBag()->set('googleRedirectRoute', $redirectRoute);
        return $this->render('ObjectsAPIBundle:General:error.html.twig');
    }

}
