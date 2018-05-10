import Notify from '../../notify';
import { Router, ActivatedRoute } from '@angular/router';
import { OnInit, OnDestroy, Injectable, Component } from '@angular/core';
import { Subscription } from 'rxjs';
import { ArticleService, APIArticle } from '../../services/article';

@Component({
  selector: 'app-articles-article',
  templateUrl: './article.component.html'
})
@Injectable()
export class ArticlesArticleComponent implements OnInit, OnDestroy {
  private routeSub: Subscription;
  public article: APIArticle;

  constructor(
    private router: Router,
    private route: ActivatedRoute,
    private articleService: ArticleService
  ) {}

  ngOnInit(): void {
    this.routeSub = this.route.params.subscribe(params => {
      this.articleService
        .getArticles({
          catname: params.catname,
          limit: 1,
          fields: 'html'
        })
        .subscribe(
          response => {
            if (response.items.length <= 0) {
              this.router.navigate(['/error-404']);
              return;
            }

            this.article = response.items[0];

            /*this.$scope.pageEnv({
          layout: {
            blankPage: false,
            needRight: false
          },
          name: 'page/32/name',
          pageId: 32,
          args: {
            ARTICLE_NAME: this.article.name,
            ARTICLE_CATNAME: this.article.catname
          }
        });*/
          },
          response => {
            if (response.status === 404) {
              this.router.navigate(['/error-404']);
            } else {
              Notify.response(response);
            }
          }
        );
    });
  }

  ngOnDestroy(): void {
    this.routeSub.unsubscribe();
  }
}
