<?php

/*
 * This file is part of the Behat WebApiExtension.
 * (c) Konstantin Kudryashov <ever.zet@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Behat\WebApiExtension\Context;

use Assert\Assertion;
use Behat\Gherkin\Node\PyStringNode;
use Behat\Gherkin\Node\TableNode;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Request;

/**
 * Provides web API description definitions.
 *
 * @author Konstantin Kudryashov <ever.zet@gmail.com>
 */
class WebApiContext implements ApiClientAwareContext
{
    /**
     * @var string
     */
    private $authorization;

    /**
     * @var ClientInterface
     */
    private $client;

    /**
     * @var array
     */
    private $headers = [];

    /**
     * @var \Psr\Http\Message\RequestInterface
     */
    private $request;

    /**
     * @var \Psr\Http\Message\ResponseInterface
     */
    private $response;

    private $placeHolders = [];

    /**
     * {@inheritdoc}
     */
    public function setClient(ClientInterface $client)
    {
        $this->client = $client;
    }

    /**
     * Adds Basic Authentication header to next request.
     *
     * @param string $username
     * @param string $password
     *
     * @Given /^I am authenticating as "([^"]*)" with "([^"]*)" password$/
     */
    public function iAmAuthenticatingAs($username, $password)
    {
        $this->removeHeader('Authorization');
        $this->authorization = base64_encode($username.':'.$password);
        $this->addHeader('Authorization', 'Basic '.$this->authorization);
    }

    /**
     * Sets a HTTP Header.
     *
     * @param string $name  header name
     * @param string $value header value
     *
     * @Given /^I set header "([^"]*)" with value "([^"]*)"$/
     */
    public function iSetHeaderWithValue($name, $value)
    {
        $this->addHeader($name, $value);
    }

    /**
     * Sends HTTP request to specific relative URL.
     *
     * @param string $method request method
     * @param string $url    relative url
     *
     * @When /^(?:I )?send a ([A-Z]+) request to "([^"]+)"$/
     */
    public function iSendARequest($method, $url)
    {
        $url = $this->prepareUrl($url);

        $this->sendRequest($method, $url, [
            'headers' => $this->headers,
        ]);
    }

    /**
     * Sends HTTP request to specific URL with field values from Table.
     *
     * @param string    $method request method
     * @param string    $url    relative url
     * @param TableNode $post   table of post values
     *
     * @When /^(?:I )?send a ([A-Z]+) request to "([^"]+)" with values:$/
     */
    public function iSendARequestWithValues($method, $url, TableNode $post)
    {
        $url = $this->prepareUrl($url);
        $fields = [];

        foreach ($post->getRowsHash() as $key => $val) {
            $fields[$key] = $this->replacePlaceHolder($val);
        }

        $this->sendRequest($method, $url, [
            'headers' => $this->headers,
            'json'    => $fields,
        ]);
    }

    /**
     * Sends HTTP request to specific URL with raw body from PyString.
     *
     * @param string       $method request method
     * @param string       $url    relative url
     * @param PyStringNode $string request body
     *
     * @When /^(?:I )?send a ([A-Z]+) request to "([^"]+)" with body:$/
     */
    public function iSendARequestWithBody($method, $url, PyStringNode $string)
    {
        $url = $this->prepareUrl($url);
        $string = $this->replacePlaceHolder(trim($string));

        $this->sendRequest($method, $url, [
            'headers' => $this->headers,
            'body'    => $string,
        ]);
    }

    /**
     * Sends HTTP request to specific URL with form data from PyString.
     *
     * @param string       $method request method
     * @param string       $url    relative url
     * @param PyStringNode $body   request body
     *
     * @When /^(?:I )?send a ([A-Z]+) request to "([^"]+)" with form data:$/
     */
    public function iSendARequestWithFormData($method, $url, PyStringNode $body)
    {
        $url = $this->prepareUrl($url);
        $body = $this->replacePlaceHolder(trim($body));

        $fields = [];
        parse_str(implode('&', explode("\n", $body)), $fields);

        $this->sendRequest($method, $url, [
            'headers'     => $this->headers,
            'form_params' => $fields,
        ]);
    }

    /**
     * Checks that response has specific status code.
     *
     * @param string $code status code
     *
     * @Then /^(?:the )?response code should be (\d+)$/
     */
    public function theResponseCodeShouldBe($code)
    {
        $expected = intval($code);
        $actual = intval($this->getResponse()->getStatusCode());
        Assertion::eq($actual, $expected);
    }

    /**
     * Checks that response body contains specific text.
     *
     * @param string $text
     *
     * @Then /^(?:the )?response should contain "([^"]*)"$/
     */
    public function theResponseShouldContain($text)
    {
        $expectedRegexp = '/'.preg_quote($text).'/i';
        $actual = (string) $this->getResponse()->getBody()->getContents();
        Assertion::regex($actual, $expectedRegexp);
    }

    /**
     * Checks that response body doesn't contains specific text.
     *
     * @param string $text
     *
     * @Then /^(?:the )?response should not contain "([^"]*)"$/
     */
    public function theResponseShouldNotContain($text)
    {
        $expectedRegexp = '/'.preg_quote($text).'/';
        $actual = (string) $this->getResponse()->getBody()->getContents();

        try {
            Assertion::regex($actual, $expectedRegexp);
        } catch (\InvalidArgumentException $e) {
            return;
        }

        $message = sprintf('Value "%s" matches expression.', $actual);
        throw new \InvalidArgumentException($message, Assertion::INVALID_REGEX, null, $actual, ['pattern' => $expectedRegexp]);
    }

    /**
     * Checks that response body contains JSON from PyString.
     *
     * Do not check that the response body /only/ contains the JSON from PyString,
     *
     * @param PyStringNode $jsonString
     *
     * @throws \RuntimeException
     *
     * @Then /^(?:the )?response should contain json:$/
     */
    public function theResponseShouldContainJson(PyStringNode $jsonString)
    {
        $etalon = json_decode($this->replacePlaceHolder($jsonString->getRaw()), true);
        $actual = json_decode($this->getResponse()->getBody()->getContents(), true);

        if (null === $etalon) {
            throw new \RuntimeException(
              "Can not convert etalon to json:\n".$this->replacePlaceHolder($jsonString->getRaw())
            );
        }

        if (null === $actual) {
            throw new \RuntimeException(
              "Can not convert actual to json:\n".$this->replacePlaceHolder((string) $this->getResponse()->getBody()->getContents())
            );
        }

        Assertion::greaterOrEqualThan(count($actual), count($etalon));
        foreach ($etalon as $key => $needle) {
            Assertion::keyExists($actual, $key);
            Assertion::eq($actual[$key], $etalon[$key]);
        }
    }

    /**
     * Check if the response header has a specific value.
     *
     * @param string $httpHeader
     * @param string $expected
     *
     * @Then /^the response "([^"]*)" header should be "([^"]*)"$/
     */
    public function theResponseHeaderShouldBe($httpHeader, $expected)
    {
        $actual = $this->getResponse()->getHeaderLine($httpHeader);
        Assertion::eq($expected, $actual);
    }

    /**
     * Prints last response body.
     *
     * @Then print response
     */
    public function printResponse()
    {
        $request = '';
        if ($this->getRequest() instanceof Request) {
            $request = sprintf(
                '%s %s => ',
                $this->getRequest()->getMethod(),
                $this->getRequest()->getUri()
            );
        }

        $response = sprintf(
            "%d:\n%s",
            $this->getResponse()->getStatusCode(),
            $this->getResponse()->getBody()
        );

        echo $request.$response;
    }

    /**
     * Prepare URL by replacing placeholders and trimming slashes.
     *
     * @param string $url
     *
     * @return string
     */
    private function prepareUrl($url)
    {
        return ltrim($this->replacePlaceHolder($url), '/');
    }

    /**
     * Sets place holder for replacement.
     *
     * you can specify placeholders, which will
     * be replaced in URL, request or response body.
     *
     * @param string $key   token name
     * @param string $value replace value
     */
    public function setPlaceHolder($key, $value)
    {
        $this->placeHolders[$key] = $value;
    }

    /**
     * Replaces placeholders in provided text.
     *
     * @param string $string
     *
     * @return string
     */
    protected function replacePlaceHolder($string)
    {
        foreach ($this->placeHolders as $key => $val) {
            $string = str_replace($key, $val, $string);
        }

        return $string;
    }

    /**
     * Returns headers, that will be used to send requests.
     *
     * @return array
     */
    protected function getHeaders()
    {
        return $this->headers;
    }

    /**
     * Adds header.
     *
     * @param string $name
     * @param string $value
     */
    protected function addHeader($name, $value)
    {
        if (isset($this->headers[$name])) {
            if (!is_array($this->headers[$name])) {
                $this->headers[$name] = [$this->headers[$name]];
            }

            $this->headers[$name][] = $value;
        } else {
            $this->headers[$name] = $value;
        }
    }

    /**
     * Removes a header identified by $headerName.
     *
     * @param string $headerName
     */
    protected function removeHeader($headerName)
    {
        if (array_key_exists($headerName, $this->headers)) {
            unset($this->headers[$headerName]);
        }
    }

    /**
     * Returns the response object.
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    protected function getResponse()
    {
        Assertion::notNull($this->response);

        return $this->response;
    }

    /**
     * @param string $method
     * @param string $url
     * @param array  $options
     */
    private function sendRequest($method, $url, $options)
    {
        try {
            $this->response = $this->getClient()->request($method, $url, $options);
        } catch (ClientException $e) {
            $this->request = $e->getRequest();
            if (null === $e->getResponse()) {
                throw $e;
            }
            $this->response = $e->getResponse();
        }
    }

    /**
     * Returns the response object.
     *
     * @return \Psr\Http\Message\RequestInterface
     */
    protected function getRequest()
    {
        return $this->request;
    }

    /**
     * @return \GuzzleHttp\ClientInterface
     */
    protected function getClient()
    {
        Assertion::notNull($this->client, 'Client has not been set in WebApiContext');

        return $this->client;
    }
}
