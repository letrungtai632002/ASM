<?php

namespace App\Controller;

use App\Entity\Order;
use App\Entity\OrderDetail;
use App\Entity\Product;
use App\Form\ProductType;
use App\Repository\BrandRepository;
use App\Repository\OrderDetailRepository;
use App\Repository\OrderRepository;
use App\Repository\ProductRepository;
use Doctrine\Common\Collections\Criteria;
use Doctrine\Persistence\ManagerRegistry;
use Exception;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use App\Entity\Brand;

/**
 * @Route("/product")
 */
class ProductController extends AbstractController
{

    /**
     * @Route("/addCart/{id}", name="app_add_cart", methods={"GET"})
     */
    public function addCart(Product $product, Request $request)
    {
        $session = $request->getSession();
        $quantity = (int)$request->query->get('quantity');

        //check if cart is empty
        if (!$session->has('cartElements')) {
            //if it is empty, create an array of pairs (prod Id & quantity) to store first cart element.
            $cartElements = array($product->getId() => $quantity);
            //save the array to the session for the first time.
            $session->set('cartElements', $cartElements);
        } else {
            $cartElements = $session->get('cartElements');
            //Add new product after the first time. (would UPDATE new quantity for added product)
            $cartElements = array($product->getId() => $quantity) + $cartElements;
            //Re-save cart Elements back to session again (after update/append new product to shopping cart)
            $session->set('cartElements', $cartElements);
        }
//        return new Response(); //means 200, successful
        return $this->redirectToRoute('app_product_show', ['id'=> $product->getId()], Response::HTTP_SEE_OTHER);


    }
    /**
     * @Route("/reviewCart", name="app_review_cart", methods={"GET"})
     */
    public function reviewCart(Request $request): Response
    {
        $session = $request->getSession();
        if ($session->has('cartElements')) {
            $cartElements = $session->get('cartElements');
        } else
            $cartElements = [];
      return $this->json($cartElements);
//        return $this->renderForm('cart/review.html.twig', [
//                  'cartElements' => $cartElements,
//          ]);

    }

//    public function reviewCart(Request $request,OrderDetail $orderDetail): Response
//    {
//        $session = $request->getSession();
//        if ($session->has('cartElements')) {
//            $cartElements = $session->get('cartElements');
//        } else
//            $cartElements = [];
//        return $this->renderForm('cart/review.html.twig', [
//
//        ]);
//    }
    /**
     * @Route("/checkoutCart", name="app_checkout_cart", methods={"GET"})
     */
    public function checkoutCart(Request               $request,
                                 OrderDetailRepository $orderDetailRepository,
                                 OrderRepository       $orderRepository,
                                 ProductRepository     $productRepository,
                                 ManagerRegistry       $mr): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $entityManager = $mr->getManager();
        $session = $request->getSession(); //get a session
        // check if session has elements in cart
        if ($session->has('cartElements') && !empty($session->get('cartElements'))) {
            try {
                // start transaction!
                $entityManager->getConnection()->beginTransaction();
                $cartElements = $session->get('cartElements');

                //Create new Order and fill info for it. (Skip Total temporarily for now)
                $order = new Order();
                date_default_timezone_set('Asia/Ho_Chi_Minh');
                $order->setDate(new \DateTime());
                /** @var \App\Entity\User $user */
                $user = $this->getUser();
                $order->setUser($user);
                $orderRepository->add($order, true); //flush here first to have ID in Order in DB.

                //Create all Order Details for the above Order
                $total = 0;
                foreach ($cartElements as $product_id => $quantity) {
                    $product = $productRepository->find($product_id);
                    //create each Order Detail
                    $orderDetail = new OrderDetail();
                    $orderDetail->setOrd($order);
                    $orderDetail->setProduct($product);
                    $orderDetail->setQuantity($quantity);
                    $orderDetailRepository->add($orderDetail);

                    $total += $product->getPrice() * $quantity;
                }
                $order->setTotal($total);
                $orderRepository->add($order);
                // flush all new changes (all order details and update order's total) to DB
                $entityManager->flush();

                // Commit all changes if all changes are OK
                $entityManager->getConnection()->commit();

                // Clean up/Empty the cart data (in session) after all.
                $session->remove('cartElements');
            } catch (Exception $e) {
                // If any change above got trouble, we roll back (undo) all changes made above!
                $entityManager->getConnection()->rollBack();
            }
            return new Response("Check in DB to see if the checkout process is successful");
        } else
            return new Response("Nothing in cart to checkout!");
    }

    /**
     * @Route("/new", name="app_product_new", methods={"GET", "POST"})
     */
    public function new(Request $request, ProductRepository $productRepository): Response
    {
        $product = new Product();
        $form = $this->createForm(ProductType::class, $product);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $productRepository->add($product, true);

            return $this->redirectToRoute('app_product_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->renderForm('product/new.html.twig', [
            'product' => $product,
            'form' => $form,
        ]);
    }

    /**
     * @Route("/manage", name="app_product_manage", methods={"GET"})
     */
    public function manage(ProductRepository $productRepository): Response
    {
        return $this->render('product/manage.html.twig', [
            'products' => $productRepository->findAll(),
        ]);
    }
    /**
     * @Route("/search", name="app_product_search", methods={"GET"})
     */
    public function test(ProductRepository $productRepository,Request $request,BrandRepository $brandRepository, int $pageId = 1): Response
    {
        $name = $request->query->get('name');
        $selectedBrand = $request->query->get('brand');
//        return $this->render('product/manage.html.twig', [
//            'products' => $productRepository->productjoinbrand($name,$selectedBrand),
//        ]);
        $filteredList=$productRepository->productjoinbrand($name,$selectedBrand);

        return $this->renderForm('product/index.html.twig', [
            'products' => $filteredList,
            'brands' => $brandRepository->findAll(),
            'numOfPages' => 1

        ]);
    }

    /**
     * @Route("/{pageId}", name="app_product_index", methods={"GET"})
     * @param ProductRepository $productRepository
     * @param Request $request
     * @param $orderBy
     * @param int $pageId
     * @return Response
     */
    public function index(ProductRepository $productRepository, BrandRepository $brandRepository, Request $request, int $pageId = 1): Response
    {
        $brand = $request->query->get('brand');
        $minPrice = $request->query->get('minPrice');
        $maxPrice = $request->query->get('maxPrice');
        $Name = $request->query->get('name');
        $sortBy = $request->query->get('sort');
        $orderBy = $request->query->get('order');

        $expressionBuilder = Criteria::expr();
        $criteria = new Criteria();
        if (!is_null($minPrice) || empty($minPrice)) {
            $minPrice = 0;
        }
        $criteria->where($expressionBuilder->gte('price', $minPrice));
        if (!is_null($maxPrice) && !empty(($maxPrice))) {
            $criteria->andWhere($expressionBuilder->lte('price', $maxPrice));
        }
        if (!is_null($Name) && !empty(($Name))) {
            $criteria->andWhere($expressionBuilder->contains('name', $Name));
            $criteria->orWhere($expressionBuilder->contains('description', $Name));

        }
        if (($brand!='None') && (!is_null($Name)) ) {
            $criteria->andWhere($expressionBuilder->eq('brand', $brand));
        }
        if(!empty($sortBy)){
            $criteria->orderBy([$sortBy => ($orderBy == 'asc') ? Criteria::ASC : Criteria::DESC]);
        }

        $filteredList = $productRepository->matching($criteria);

        $numOfItems = $filteredList->count();   // total number of items satisfied above query
        $itemsPerPage = 8; // number of items shown each page
        $filteredList = $filteredList->slice($itemsPerPage * ($pageId - 1), $itemsPerPage);
        return $this->renderForm('product/index.html.twig', [
            'products' => $filteredList,
            'brands' => $brandRepository->findAll(),
            'numOfPages' => ceil($numOfItems / $itemsPerPage)

        ]);

    }

    /**
     * @Route("/show/{id}", name="app_product_show", methods={"GET"})
     */
    public function show(Product $product): Response
    {
        return $this->render('product/show.html.twig', [
            'product' => $product,
        ]);
    }

    /**
     * @Route("/{id}/edit", name="app_product_edit", methods={"GET", "POST"})
     */
    public function edit(Request $request, Product $product, ProductRepository $productRepository): Response
    {
        $form = $this->createForm(ProductType::class, $product, array("no_edit" => true));
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $productRepository->add($product, true);

            return $this->redirectToRoute('app_product_manage', [], Response::HTTP_SEE_OTHER);
        }

        return $this->renderForm('product/edit.html.twig', [
            'product' => $product,
            'form' => $form,
        ]);
    }

    /**
     * @Route("/{id}", name="app_product_delete", methods={"POST"})
     */
    public function delete(Request $request, Product $product, ProductRepository $productRepository): Response
    {
        if ($this->isCsrfTokenValid('delete'.$product->getId(), $request->request->get('_token'))) {
            $productRepository->remove($product, true);
        }

        return $this->redirectToRoute('app_product_index', [], Response::HTTP_SEE_OTHER);
    }
}
