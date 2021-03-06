<?php
namespace Bullet\Tests;
use Bullet;

class RequestTest extends \PHPUnit_Framework_TestCase
{
    function testMethod()
    {
        $r = new Bullet\Request('POST', '/foo/bar');
        $this->assertEquals('POST', $r->method());
    }

    function testMethodSupportsPatch()
    {
        $r = new Bullet\Request('PATCH', '/foo/bar');
        $this->assertEquals('PATCH', $r->method());
    }

    function testUrl()
    {
        $r = new Bullet\Request('DELETE', '/foo/bar/');
        $this->assertEquals('/foo/bar/', $r->url());
    }

    function testFormatDefaultsToHtml()
    {
        $r = new Bullet\Request('DELETE', '/foo/bar/');
        $this->assertEquals('html', $r->format());
    }

    function testExtensionOverridesAcceptHeader()
    {
        $r = new Bullet\Request('PUT', '/users/42.xml', array(), array('Accept' => 'text/html,application/json'));
        $this->assertEquals('xml', $r->format());
    }

    function testAccept()
    {
        $r = new Bullet\Request('', '', array(), array('Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8,application/json'));
        $this->assertTrue($r->accept('html'));
        $this->assertTrue($r->accept('xhtml'));
        $this->assertTrue($r->accept('xml'));
        $this->assertTrue($r->accept('json'));
        $this->assertFalse($r->accept('csv'));
    }

    function testAcceptHeader()
    {
        $r = new Bullet\Request('', '', array(), array('Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8,application/json'));
        $this->assertEquals(array(
          "text/html" => "text/html",
          "application/xhtml+xml" => "application/xhtml+xml",
          "application/json" => "application/json",
          "application/xml" => "application/xml",
          "*/*" => "*/*"
        ), $r->accept());
    }

    function testAcceptHeaderOverridesDefaultHtmlInApp()
    {
        $app = new Bullet\App();
        // Accept only JSON and request URL with no extension
        $req = new Bullet\Request('PUT', '/foo', array(), array('Accept' => 'application/json'));
        $app->path('foo', function($request) use($app) {
            $app->format('json', function($request) {
                return array('foo' => 'bar');
            });
            $app->format('html', function($request) {
                return '<html></html>';
            });
        });
        $res = $app->run($req);
        $this->assertEquals('json', $req->format());
        $this->assertEquals('{"foo":"bar"}', $res->content());
        $this->assertEquals('application/json', $res->contentType());
    }

    function testRawUrlencodedBodyIsDecodedInPutRequest()
    {
        $r = new Bullet\Request('PUT', '/users/123.json', array(), array('Accept' => 'application/json'), 'id=123&foo=bar&bar=bar+baz');
        $this->assertEquals('123', $r->id);
        $this->assertEquals('bar baz', $r->bar);
    }

    function testRawJsonBodyIsDecodedInPutRequest()
    {
        $r = new Bullet\Request('PUT', '/users/42.json', array(), array('Accept' => 'application/json'), '{"id":"123"}');
        $this->assertEquals('123', $r->id);
    }

    function testRawJsonBodyIsDecodedInPostRequest()
    {
        $r = new Bullet\Request('POST', '/users/129.json', array(), array('Accept' => 'application/json'), '{"id":"124"}');
        $this->assertEquals('124', $r->id);
    }

    function testRawJsonBodyIsIgnoredInPostRequestIfPostParamsAreSet()
    {
        $r = new Bullet\Request('POST', '/users/129.json', array('id' => '123'), array('Accept' => 'application/json'), '{"id":"124"}');
        $this->assertEquals('123', $r->id);
    }
}
