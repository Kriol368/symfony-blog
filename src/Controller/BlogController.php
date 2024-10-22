<?php

namespace App\Controller;

use App\Entity\Comment;
use App\Entity\Post;
use App\Form\CommentFormType;
use App\Form\PostFormType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\String\Slugger\SluggerInterface;

class BlogController extends AbstractController
{
    #[Route('/blog', name: 'blog')]
    public function blog(ManagerRegistry $doctrine): Response
    {
        // Fetch all posts from the database
        $repositorio = $doctrine->getRepository(Post::class);
        $allPosts = $repositorio->findAll();

        return $this->render('blog.html.twig', [
            'allPosts' => $allPosts, // Pass all posts to the template
        ]);
    }

    #[Route('/single_post/{slug}', name: 'single_post')]
    public function post(ManagerRegistry $doctrine, Request $request, SluggerInterface $slugger, $slug): Response
    {
        $repositorio = $doctrine->getRepository(Post::class);
        $post = $repositorio->findOneBy(["slug" => $slug]);

        if (!$post) {
            throw $this->createNotFoundException('The post does not exist');
        }

        // Fetch the comments related to the post
        $commentRepository = $doctrine->getRepository(Comment::class);
        $comments = $commentRepository->findBy(['post' => $post]);

        // Create a new comment form
        $comment = new Comment();
        $form = $this->createForm(CommentFormType::class, $comment);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $comment->setPost($post);
            $comment->setPublishedAt(new \DateTime()); // Set current date

            $entityManager = $doctrine->getManager();
            $entityManager->persist($comment);
            $entityManager->flush();

            // Optional flash message
            $this->addFlash('success', 'Comment added successfully!');

            // Redirect to the same post page to display the new comment
            return $this->redirectToRoute('single_post', ["slug" => $post->getSlug()]);
        }

        return $this->render('blog/single_post.html.twig', [
            'post' => $post,
            'comments' => $comments,
            'form' => $form->createView(), // Pass the form to the template
        ]);
    }




    #[Route('/blog/new', name: 'new_post')]
    public function newPost(ManagerRegistry $doctrine, Request $request, SluggerInterface $slugger): Response
    {
        $post = new Post();

        // Assuming your PostFormType has an image field
        $form = $this->createForm(PostFormType::class, $post);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $post = $form->getData();
            $post->setSlug($slugger->slug($post->getTitle())->toString());
            $post->setPostUser($this->getUser());
            $post->setNumLikes(0);
            $post->setNumComments(0);

            // Handling file upload for the image
            $file = $form->get('image')->getData();
            if ($file) {
                $originalFilename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
                $safeFilename = $slugger->slug($originalFilename);
                $newFilename = $safeFilename.'-'.uniqid().'.'.$file->guessExtension();

                try {
                    $file->move(
                        $this->getParameter('images_directory'),
                        $newFilename
                    );
                } catch (FileException $e) {
                    // Handle the exception during file upload
                }

                // Set the image filename in the Post entity
                $post->setImage($newFilename);
            }

            $entityManager = $doctrine->getManager();
            $entityManager->persist($post);
            $entityManager->flush();

            // Optional flash message
            $this->addFlash('success', 'Post created successfully!');

            return $this->redirectToRoute('single_post', ["slug" => $post->getSlug()]);
        }

        return $this->render('blog/new_post.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/single_post/{slug}/like', name: 'post_like')]
    public function like(ManagerRegistry $doctrine, $slug): Response
    {
        $repository = $doctrine->getRepository(Post::class);
        $post = $repository->findOneBy(["slug" => $slug]);

        if ($post) {
            $post->like();
            $entityManager = $doctrine->getManager();
            $entityManager->persist($post);
            $entityManager->flush();
        }

        return $this->redirectToRoute('single_post', ["slug" => $post->getSlug()]);
    }

    #[Route("/blog/buscar/{page}", name: "blog_buscar")]
    public function buscar(ManagerRegistry $doctrine, Request $request, int $page = 1): Response
    {
        $repository = $doctrine->getRepository(Post::class);
        $searchTerm = $request->query->get('searchTerm', '');
        $posts = $repository->findByTextPaginated($page, $searchTerm);
        $recents = $repository->findRecents();

        return $this->render('blog.html.twig', [
            'posts' => $posts,      // Asegúrate de que esta línea esté presente
            'recents' => $recents,
            'searchTerm' => $searchTerm,
        ]);
    }
}
