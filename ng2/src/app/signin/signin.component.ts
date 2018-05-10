import { Component, Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { AuthService } from '../services/auth.service';
import {
  APILoginServicesGetResponse,
  APILoginStartPostResponse,
  APILoginServices
} from '../services/api.service';

interface SignInService {
  id: string;
  name: string;
  icon: string;
}

@Component({
  selector: 'app-signin',
  templateUrl: './signin.component.html'
})
@Injectable()
export class SignInComponent {
  public services: SignInService[] = [];
  public form = {
    login: '',
    password: '',
    remember: false
  };
  public invalidParams: any = {};

  constructor(public auth: AuthService, private http: HttpClient) {
    /*this.$scope.pageEnv({
          layout: {
              blankPage: false,
              needRight: false
          },
          name: 'page/79/name',
          pageId: 79
      });*/

    this.http.get<APILoginServicesGetResponse>('/api/login/services').subscribe(
      response => {
        for (const key in response.items) {
          if (response.items.hasOwnProperty(key)) {
            const item = response.items[key];
            this.services.push({
              id: key,
              name: item.name,
              icon: item.icon
            });
          }
        }
      },
      response => {
        console.log(response);
      }
    );
  }

  public submit($event) {
    $event.preventDefault();

    this.auth
      .login(this.form.login, this.form.password, this.form.remember)
      .then(
        user => {},
        response => {
          if (response.status === 400) {
            this.invalidParams = response.error.invalid_params;
          } else {
            console.log(response);
          }
        }
      );
  }

  public start(serviceId: string) {
    this.http
      .get<APILoginStartPostResponse>('/api/login/start', {
        params: {
          type: serviceId
        }
      })
      .subscribe(
        response => {
          window.location.href = response.url;
        },
        response => {
          console.log(response);
        }
      );
  }
}
