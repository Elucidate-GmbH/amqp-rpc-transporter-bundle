<?php

namespace Leberknecht\AmqpRpcTransporterBundle\Tests\Transport;

use AMQPChannel;
use AMQPEnvelope;
use AMQPExchange;
use AMQPQueue;
use Leberknecht\AmqpRpcTransporterBundle\Transport\AmqpRpcTransceiver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpFactory;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\Connection;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpReceivedStamp;

class AmqpRpcTransceiverTest extends TestCase
{
    private AmqpRpcTransceiver $amqpRpcTransceiver;

    private MockObject | AmqpFactory $amqpFactoryMock;

    private MockObject | AMQPExchange $exchangeMock;

    public function setUp(): void
    {
        $connectionMock = $this->createMock(Connection::class);

        $this->amqpFactoryMock = $this->getMockBuilder(AmqpFactory::class)->disableOriginalConstructor()->getMock();
        $this->exchangeMock = $this->getMockBuilder(AMQPExchange::class)->disableOriginalConstructor()->getMock();
        $this->amqpRpcTransceiver = new AmqpRpcTransceiver(
            $connectionMock
        );

        $this->amqpRpcTransceiver->setExchange($this->exchangeMock);
        $this->amqpRpcTransceiver->setAmqpFactory($this->amqpFactoryMock);
    }

    /**
     * @group alma
     */
    public function testSend()
    {
        $queueMock = $this->getMockBuilder(AMQPQueue::class)->disableOriginalConstructor()->getMock();
        $properties = $this->getMockBuilder(AMQPEnvelope::class)->disableOriginalConstructor()->getMock();
        $correlationId = 42;
        $properties->expects($this->once())->method('getCorrelationId')->willReturn($correlationId);
        $properties->expects($this->once())->method('getBody')->willReturn('test');
        $queueMock->expects($this->once())->method('get')->willReturn($properties);
        $this->amqpFactoryMock->method('createQueue')->willReturn($queueMock);
        $this->amqpRpcTransceiver->send(new Envelope(new \stdClass()), $correlationId);
    }

    public function testAck()
    {
        $envelope = new Envelope(new \stdClass());
        /** @var AMQPEnvelope | MockObject $amqpEnvelopeMock */
        $amqpEnvelopeMock = $this->getMockBuilder(AMQPEnvelope::class)->disableOriginalConstructor()->getMock();
        $amqpEnvelopeMock->expects($this->once())->method('getReplyTo')->willReturn('test_reply');
        $amqpEnvelopeMock->expects($this->once())->method('getCorrelationId')->willReturn('123');

        $envelope = $envelope->with(new AmqpReceivedStamp($amqpEnvelopeMock, 'test'));
        $envelope = $envelope->with(new HandledStamp(42, 'test'));
        $this->exchangeMock->expects($this->once())->method('publish')->with(42, 'test_reply', AMQP_NOPARAM, [
            'correlation_id' => 123
        ]);
        $this->amqpRpcTransceiver->ack($envelope);
    }
}