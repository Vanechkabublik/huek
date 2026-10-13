// HTTP-эндпоинты авторизации:
//   POST /auth/register  — регистрация (публичный), токен НЕ выдаёт
//   POST /auth/login     — логин (публичный)
//   POST /auth/logout    — выход (публичный: удалить cookie можно и с протухшим токеном)
//   GET  /auth/me        — текущий пользователь (нужен токен)
//
// @Res({ passthrough: true }) — даёт доступ к express Response, чтобы поставить cookie,
// но при этом Nest сам отправляет то, что вернул метод (не нужно вызывать res.json()).

import { Body, Controller, Get, HttpCode, Post, Res } from '@nestjs/common';
import type { Response } from 'express';

import { User } from '../user/user.entity';
import { CurrentUser, Public } from './auth.decorators';
import { LoginDto, RegisterDto } from './auth.dto';
import { AuthService } from './auth.service';

@Controller('auth')
export class AuthController {
  constructor(private readonly authService: AuthService) {}

  @Public()
  @Post('register')
  register(@Body() dto: RegisterDto) {
    return this.authService.register(dto);
  }

  @Public()
  @HttpCode(200)
  @Post('login')
  login(@Body() dto: LoginDto, @Res({ passthrough: true }) res: Response) {
    return this.authService.login(dto, res);
  }

  @Public()
  @HttpCode(200)
  @Post('logout')
  logout(@Res({ passthrough: true }) res: Response) {
    return this.authService.logout(res);
  }

  // Без @Public → guard уже проверил токен и положил юзера в request.user.
  @Get('me')
  me(@CurrentUser() user: User) {
    return user;
  }
}
