<h1>Comments</h1>
<?php echo $session->flash(); ?>

<?php if ($comments): ?>
<div class="paginator"><div class="subpaginator">
<?php echo $paginator->prev().' '.$paginator->numbers().' '.$paginator->next(); ?>
</div></div>

<div class="comments">
<?php $count = 0; ?>
<?php foreach ($comments as $comment): ?>
<div class="comment <?php echo ($count++%2)?'even':'odd'; ?>">
<div class="meta">
<span class="from"><?php echo $comment['Comment']['name'] ?></span> said 
<span class="date"><?php echo $time->relativeTime($comment['Comment']['date']); ?></span>
</div><!-- comment meta -->

<div class="text">
<?php 
  $img = '<img src="'.Router::url('/media/mini/'.$comment['Media']['id'].'/'.$comment['Media']['name']).'" />';
  $link = '/images/view/'.$comment['Media']['id'];
  echo '<div class="image">'.$html->link($img, $link, array('escape' => false)).'</div>';?>
<?php echo preg_replace('/\n/', '<br />', $text->truncate($comment['Comment']['text'], 220, array('ending' => '...', 'exact' => false, 'html' => false))); ?>
</div>
</div><!-- comment -->
<?php endforeach; /* comments */ ?>
</div><!-- comments -->

<div class="paginator"><div class="subpaginator">
<?php echo $paginator->prev().' '.$paginator->numbers().' '.$paginator->next(); ?>
</div></div>

<?php endif; ?>
