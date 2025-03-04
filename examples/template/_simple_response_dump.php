<?php if (\Mews\Pos\Gateways\AbstractGateway::TX_CANCEL === $transaction): ?>
    <h5 class="text-center">NOT: Iptal işlemi 12 saat (bankaya gore degisir) <b>gecmemis</b> odeme icin yapilabilir</h5>
<?php endif; ?>
<?php if (\Mews\Pos\Gateways\AbstractGateway::TX_REFUND === $transaction): ?>
    <h5 class="text-center">NOT: İade işlemi 12 saat (bankaya gore degisir) <b>gecmis</b> odeme icin yapilabilir</h5>
<?php endif; ?>
<div class="result">
    <h3 class="text-center text-<?= $pos->isSuccess() ? 'success' : 'danger'; ?>">
        <?= $transaction ?> <?= $pos->isSuccess() ? 'order is successful!' : 'order is not successful!'; ?>
    </h3>
    <dl class="row">
        <dt class="col-sm-12">All Data Dump:</dt>
        <dd class="col-sm-12">
            <pre><?php dump($response); ?></pre>
        </dd>
    </dl>
    <hr>
    <div class="text-right">
        <a href="index.php" class="btn btn-lg btn-info">&lt; Click to payment form</a>
    </div>
</div>
