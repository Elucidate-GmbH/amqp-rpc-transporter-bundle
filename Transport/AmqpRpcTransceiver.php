<?php

namespace Leberknecht\AmqpRpcTransporterBundle\Transport;

use AMQPChannelException;
use AMQPConnectionException;
use AMQPException;
use AMQPExchange;
use AMQPQueue;
use Leberknecht\AmqpRpcTransporterBundle\Stamp\ResponseStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\LogicException;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpFactory;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpReceivedStamp;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpReceiver;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpSender;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpStamp;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\Connection;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

class AmqpRpcTransceiver extends AmqpReceiver
{
    private Connection $connection;

    private AmqpFactory $amqpFactory;

    private AmqpSender $amqpSender;

    private AMQPExchange $exchange;

    public function __construct(Connection $connection, SerializerInterface $serializer = null)
    {
        $this->connection = $connection;
        $this->setAmqpFactory(new AmqpFactory());
        $this->setExchange(new AMQPExchange($this->connection->channel()));
        $this->amqpSender = new AmqpSender($connection, $serializer);
        parent::__construct($connection, $serializer);
    }

    public function setAmqpFactory(AmqpFactory $factory): void
    {
        $this->amqpFactory = $factory;
    }

    public function setExchange(AMQPExchange $exchange): void
    {
        $this->exchange = $exchange;
    }

    public function ack(Envelope $envelope): void
    {
        try {
            $stamp = $this->findAmqpStamp($envelope);
            $resultStamp = $this->findHandledStamp($envelope);
            $this->exchange->publish($resultStamp->getResult(), $stamp->getAmqpEnvelope()->getReplyTo(), AMQP_NOPARAM, [
                'correlation_id' => $stamp->getAmqpEnvelope()->getCorrelationId()
            ]);
        } catch (AMQPException $exception) {
            throw new TransportException($exception->getMessage(), 0, $exception);
        }
    }

    public function send(Envelope $envelope, $correlationId): Envelope
    {
        $responseQueue = $this->createResponseQueue();
        $envelope = $envelope->with(AmqpStamp::createWithAttributes([
            'reply_to' => $responseQueue->getName(),
            'correlation_id' => $correlationId
        ], $envelope->last(AmqpStamp::class)));

        $this->amqpSender->send($envelope);

        do {
            $response = $responseQueue->get();
            usleep(20000);
        } while(!$response or $response->getCorrelationId() != $correlationId);

        return $envelope->with(new ResponseStamp($response->getBody()));
    }

    private function findHandledStamp(Envelope $envelope): ?HandledStamp
    {
        $resultStamp = $envelope->last(HandledStamp::class);
        assert($resultStamp instanceof HandledStamp || null);

        return $resultStamp;
    }

    private function findAmqpStamp(Envelope $envelope): AmqpReceivedStamp
    {
        $amqpReceivedStamp = $envelope->last(AmqpReceivedStamp::class);
        assert($amqpReceivedStamp instanceof AmqpReceivedStamp || null);
        if (null === $amqpReceivedStamp) {
            throw new LogicException('No "AmqpReceivedStamp" stamp found on the Envelope.');
        }

        return $amqpReceivedStamp;
    }

    /**
     * @throws AMQPChannelException
     * @throws AMQPConnectionException
     * @throws AMQPException
     */
    private function createResponseQueue(): AMQPQueue
    {
        $alphabet = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $responseQueueName = sprintf(
            'rpc-amqp.gen-%s', substr(str_shuffle(str_repeat($alphabet, ceil(20 / strlen($alphabet)))), 1, 20)
        );
        $receiveQueue = $this->amqpFactory->createQueue($this->connection->channel());
        $receiveQueue->setFlags(AMQP_EXCLUSIVE);
        $receiveQueue->setName($responseQueueName);
        $receiveQueue->declareQueue();

        return $receiveQueue;
    }
}
