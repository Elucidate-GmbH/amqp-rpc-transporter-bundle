<?php

namespace Leberknecht\AmqpRpcTransporterBundle\Transport;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpTransport;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\Connection;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

class AmqpRpcTransport extends AmqpTransport
{
    private ?AmqpRpcTransceiver $transceiver = null;

    public function __construct(Connection $connection, SerializerInterface $serializer = null)
    {
        $this->setTransceiver(new AmqpRpcTransceiver($connection, $serializer));
        parent::__construct($connection, $serializer);
    }

    public function setTransceiver(AmqpRpcTransceiver $transceiver): void
    {
        $this->transceiver = $transceiver;
    }

    /**
     * {@inheritdoc}
     */
    public function get(): iterable
    {
        return $this->transceiver->get();
    }

    /**
     * {@inheritdoc}
     */
    public function ack(Envelope $envelope): void
    {
        $this->transceiver->ack($envelope);
    }

    /**
     * {@inheritdoc}
     */
    public function send(Envelope $envelope): Envelope
    {
        return $this->transceiver->send($envelope, mt_rand());
    }
}
