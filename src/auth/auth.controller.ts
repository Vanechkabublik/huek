// src/auth/auth.controller.ts
import { Body, Controller, Get, Post, Res } from '@nestjs/common';
import type { Response } from 'express';

import { AuthService } from './auth.service';
import { RegisterDto, LoginDto } from './auth.dto';
import { Public } from './public.decorator';
import { CurrentUser } from './current-user.decorator';

@Controller('auth')
export class AuthController {
  constructor(private readonly authService: AuthService) {}

  @Public()
  @Post('register')
  register(@Body() dto: RegisterDto, @Res({ passthrough: true }) res: Response) {
    return this.authService.register(dto, res);
  }

  @Public()
  @Post('login')
  login(@Body() dto: LoginDto, @Res({ passthrough: true }) res: Response) {
    return this.authService.login(dto, res);
  }

  @Post('logout')
  logout(@Res({ passthrough: true }) res: Response) {
    return this.authService.logout(res);
  }

  @Get('profile')
  getProfile(@CurrentUser() user: any) {
    return user;
  }
}