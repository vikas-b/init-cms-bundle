<?php

/**
 * This file is part of the Networking package.
 *
 * (c) net working AG <info@networking.ch>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Networking\InitCmsBundle\Helper;

use Networking\InitCmsBundle\Entity\Page;
use Networking\InitCmsBundle\Entity\PageSnapshot;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @author net working AG <info@networking.ch>
 */
class LanguageSwitcherHelper implements ContainerAwareInterface
{
    /**
     * @var Container $container
     */
    protected $container;

    /**
     * @var Request $request
     */
    protected $request;

    /**
     * @var RouterInterface $router
     */
    protected $router;

    /**
     * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
     */
    public function setContainer(ContainerInterface $container = null)
    {
        $this->container = $container;
        $this->request = $this->container->get('request');
        $this->router = $this->container->get('router');
    }

    /**
     * Returns the corresponding route of the given URL for the locale supplied
     * If none is found it returns the original route object
     *
     * @param $oldUrl
     * @param $locale
     * @return array|\Symfony\Cmf\Component\Routing\RouteObjectInterface
     */
    public function getTranslationRoute($oldUrl, $locale)
    {

        /** @var $router RouterInterface */
        $route = $this->router->matchRequest(Request::create($oldUrl));

        if (!array_key_exists('_content', $route)) return $route;

        $content = $route['_content'];


        if ($content instanceof Page) {

            $translation = $content->getAllTranslations()->get($locale);

            if (is_null($translation)) {
                return array('_route' => 'networking_init_cms_home');
            }

            return $translation->getContentRoute()->initializeRoute($translation);
        }

        if ($content instanceof PageSnapshot) {
            $content = $this->container->get('serializer')->deserialize($content->getVersionedData(), 'Networking\InitCmsBundle\Entity\Page', 'json');


            $translation = $content->getAllTranslations()->get($locale);

            if ($translation && $snapshotId = $translation->getId()) {
                /** @var $snapshot PageSnapshot */
                $snapshot = $this->container->get('doctrine')->getRepository($content->getSnapshotClassType())->findOneBy(array('resourceId' => $snapshotId));

                if ($snapshot) {
                    return $snapshot->getRoute();
                }
            }
        }

        throw new NotFoundHttpException(sprintf('No valid translation in "%s" found for page "%s"', $locale, $content->getMetaTitle()));

    }

    /**
     * Get the query string parameters for the current request
     * Not used at present
     *
     * @return null|string
     */
    public function getQueryString()
    {
        $qs = Request::normalizeQueryString($this->request->server->get('QUERY_STRING'));

        return '' === $qs ? null : $qs;
    }

    /**
     * Returns the uri Path for the current uri if no referrer given,
     * otherwise it returns the path for the URL given
     *
     * @param  null        $referrer
     * @return null|string
     */
    public function getPathInfo($referrer = null)
    {
        $baseUrl = $this->prepareBaseUrl($referrer);

        if (null === ($referrer)) {
            return '/';
        }

        $pathInfo = '/';

        // Remove the query string from REQUEST_URI
        if ($pos = strpos($referrer, '?')) {
            $referrer = substr($referrer, 0, $pos);
        }

        $host = $this->request->getHost();
        if ((null !== $baseUrl) && (false === ($pathInfo = substr($referrer, strlen($baseUrl))))) {
            // If substr() returns false then PATH_INFO is set to an empty string
            return '/';
        } elseif (null === $baseUrl) {
            return $referrer;
        } elseif ($pos = strpos($pathInfo, $host)) {
            $pathInfo = substr($pathInfo, $pos + strlen($host));
        }

        return (string)$pathInfo;
    }

    /**
     * Prepares the Base URL
     *
     * @param $referrer
     * @return bool|string
     */
    public function prepareBaseUrl($referrer)
    {
        $filename = basename($this->request->server->get('SCRIPT_FILENAME'));

        if (basename($this->request->server->get('SCRIPT_NAME')) === $filename) {
            $baseUrl = $this->request->server->get('SCRIPT_NAME');
        } elseif (basename($this->request->server->get('PHP_SELF')) === $filename) {
            $baseUrl = $this->request->server->get('PHP_SELF');
        } elseif (basename($this->request->server->get('ORIG_SCRIPT_NAME')) === $filename) {
            $baseUrl = $this->request->server->get('ORIG_SCRIPT_NAME'); // 1and1 shared hosting compatibility
        } else {
            // Backtrack up the script_filename to find the portion matching
            // php_self
            $path = $this->request->server->get('PHP_SELF', '');
            $file = $this->request->server->get('SCRIPT_FILENAME', '');
            $segs = explode('/', trim($file, '/'));
            $segs = array_reverse($segs);
            $index = 0;
            $last = count($segs);
            $baseUrl = '';
            do {
                $seg = $segs[$index];
                $baseUrl = '/' . $seg . $baseUrl;
                ++$index;
            } while (($last > $index) && (false !== ($pos = strpos($path, $baseUrl))) && (0 != $pos));
        }

        // Does the baseUrl have anything in common with the request_uri?
        $requestUri = $referrer;

        if ($baseUrl && false !== $prefix = $this->getUrlencodedPrefix($requestUri, $baseUrl)) {
            // full $baseUrl matches
            return $prefix;
        }

        if ($baseUrl && false !== $prefix = $this->getUrlencodedPrefix($requestUri, dirname($baseUrl))) {
            // directory portion of $baseUrl matches
            return rtrim($prefix, '/');
        }

        $truncatedRequestUri = $requestUri;
        if (($pos = strpos($requestUri, '?')) !== false) {
            $truncatedRequestUri = substr($requestUri, 0, $pos);
        }

        $basename = basename($baseUrl);

        if (empty($basename) || !strpos(rawurldecode($truncatedRequestUri), $basename)) {
            // no match whatsoever; set it blank
            return '';
        }

        // If using mod_rewrite or ISAPI_Rewrite strip the script filename
        // out of baseUrl. $pos !== 0 makes sure it is not matching a value
        // from PATH_INFO or QUERY_STRING
        if ((strlen($requestUri) >= strlen($baseUrl)) && ((false !== ($pos = strpos($requestUri, $baseUrl))) && ($pos !== 0))) {
            $baseUrl = substr($requestUri, 0, $pos + strlen($baseUrl));
        }

        return rtrim($baseUrl, '/');
    }

    /*
     * Returns the prefix as encoded in the string when the string starts with
     * the given prefix, false otherwise.
     *
     * @param string $string The urlencoded string
     * @param string $prefix The prefix not encoded
     *
     * @return string|false The prefix as it is encoded in $string, or false
     */
    /**
     * @param $string
     * @param $prefix
     * @return bool
     */
    protected function getUrlencodedPrefix($string, $prefix)
    {
        if (0 !== strpos(rawurldecode($string), $prefix)) {
            return false;
        }

        $len = strlen($prefix);

        if (preg_match("#^(%[[:xdigit:]]{2}|.){{$len}}#", $string, $match)) {
            return $match[0];
        }

        return false;
    }

}
